<?php

/**
 * 声明应用的命名空间，严格遵循 PSR-4 自动加载规范。
 */
namespace App\Http\Controllers;

// 引入 Laravel 核心组件与门面 (Facades)
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

// 引入 RabbitMQ 官方推荐的底层 AMQP 客户端库
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class RabbitMQController
 * * RabbitMQ 消息队列的测试控制器。
 * 注意：在实际高并发生产环境中，此类同步阻塞的消费模式仅限于联调测试，
 * 真实的消费者应脱离 HTTP 生命周期，通过 CLI 守护进程（如 Laravel Artisan Command + Supervisor）运行。
 * * @package App\Http\Controllers
 */
class RabbitMQController extends BaseController
{
    // 引入权限授权与请求验证的 Trait，这是 Laravel 基础控制器的标准配置
    use AuthorizesRequests, ValidatesRequests;

    /**
     * 控制器构造函数
     * 可用于注入依赖服务 (Dependency Injection) 或应用中间件。
     */
    public function __construct()
    {
        // 预留依赖注入位置
    }

    /**
     * 默认的索引路由测试
     * * @return string
     */
    public function index()
    {
        return 'rabbitmq';
    }

    /**
     * RabbitMQ 消费者联调测试方法 (HTTP 触发)
     * * 模拟从 RabbitMQ 中拉取一条消息并返回。
     * 采用完整的 Try-Catch-Finally 结构，确保底层 TCP 网络资源的安全释放。
     * * @return \Illuminate\Http\JsonResponse
     */
    public function test1()
    {
        // 提前初始化连接和信道变量，避免在 catch 或 finally 块中出现未定义错误
        $connect = null;
        $channel = null;

        try {
            /**
             * 1. 建立到底层 RabbitMQ Server 的 TCP 连接
             * @param string $host     RabbitMQ 主机地址。Docker 环境跨容器通信使用 'host.docker.internal' 或容器服务名。
             * @param int    $port     AMQP 协议默认端口 5672。
             * @param string $user     登录账号 (默认 guest)。
             * @param string $password 登录密码 (默认 guest)。
             */
            $connect = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');

            /**
             * 2. 创建信道 (Channel)
             * 架构说明：AMQP 协议中，建立和销毁 TCP 连接的代价极其昂贵。
             * 因此采用了“多路复用”的设计，即在一个 TCP 连接内创建多个轻量级的 Channel。
             * 所有的业务流转（声明队列、投递、消费）都在 Channel 内完成。
             */
            $channel = $connect->channel();

            /**
             * 3. 声明队列 (Queue Declare)
             * * 幂等操作：如果队列不存在则创建，存在则什么都不做。
             * @param string $queue       队列名称，这里设为 'hello'。
             * @param bool   $passive     是否为被动模式 (false)。设为 true 时只检查队列是否存在，不存在会报错。
             * @param bool   $durable     是否持久化 (false)。设为 true 可将队列存入磁盘，RabbitMQ 重启不丢失（注：此处 false 表示存在内存中）。
             * @param bool   $exclusive   是否排他 (false)。设为 true 则仅对首次声明它的连接可见，连接断开时自动删除该队列。
             * @param bool   $auto_delete 是否自动删除 (false)。设为 true 当最后一个消费者断开后，队列会被自动删除。
             */
            $channel->queue_declare('hello', false, false, false, false);

            /**
             * 4. 定义回调函数 (Callback Closure)
             * * 当队列中有消息时，RabbitMQ 会将消息推送给此闭包函数处理。
             * * @param \PhpAmqpLib\Message\AMQPMessage $msg 接收到的消息对象。
             */
            $callback = function($msg) {
                // 生产环境中，应将消息记录到标准日志系统，方便后续链路追踪 (Trace)
                Log::info("RabbitMQ 收到消息: " . $msg->body);
            };

            /**
             * 5. 注册消费者 (Basic Consume)
             * * 告诉 RabbitMQ 服务器，当前应用准备好接收哪个队列的消息了。
             * * @param string   $queue        队列名称 ('hello')。
             * @param string   $consumer_tag 消费者标签 ('')。留空则由系统自动生成全局唯一标识。
             * @param bool     $no_local     是否不接收同一个连接发出的消息 (false)。通常用于 AMQP 协议版本兼容。
             * @param bool     $no_ack       是否自动应答 (true)。设为 true 表示消息一旦发给消费者就被认为消费成功。企业级项目中通常设为 false，业务处理完后手动发送 ACK，防止消息丢失。
             * @param bool     $exclusive    是否排他消费 (false)。设为 true 则阻止其他消费者连接到此队列。
             * @param bool     $nowait       是否不等待服务器响应 (false)。
             * @param callable $callback     处理消息的回调函数 ($callback)。
             */
            $channel->basic_consume('hello', '', false, true, false, false, $callback);

            Log::info("RabbitMQ 消费监听已注册，准备开始拉取消息...");

            /**
             * 6. 阻塞等待服务器推送消息 (Wait)
             * * 核心注意点：这里是 HTTP 请求的生命周期，绝对不能无限期死等。
             * * @param array|null $allowed_methods 允许的方法列表，此处传 null 接受任何方法。
             * @param bool       $non_blocking    是否非阻塞，此处传 true 配合 timeout 使用。
             * @param int        $timeout         【极其重要】超时时间设为 2 秒。如果 2 秒内没等到消息，主动抛出 AMQPTimeoutException 防止 PHP-FPM 进程卡死。
             */
            $channel->wait(null, true, 2);

            // 若在 2 秒内成功获取并处理了消息，返回成功响应。
            return response()->json([
                'status' => 'success',
                'message' => 'RabbitMQ 已连接，成功消费消息，请查看 storage/logs/laravel.log'
            ]);

        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            /**
             * 异常分支 1：消费超时
             * 这是一个预期的“业务级异常”。说明连接没问题，只是队列空了，所以给予正常状态码的响应。
             */
            return response()->json([
                'status' => 'success',
                'message' => '连接正常，但队列中当前无消息 (等待 2s 超时)'
            ]);

        } catch (\Exception $e) {
            /**
             * 异常分支 2：网络断开、认证失败等底层异常
             * 拦截所有不可预期的异常，记录至错误日志，对外隐藏敏感报错信息并返回 500 状态码。
             */
            Log::error("RabbitMQ 连接/消费发生致命错误: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => '内部消息服务异常，请稍后重试'
            ], 500);

        } finally {
            /**
             * 7. 兜底资源清理 (Graceful Shutdown)
             * * 无论 Try 块中是正常返回还是抛出异常，Finally 块一定会被执行。
             * 在长连接网络 I/O 开发中，这一步是防止内存泄漏 (Memory Leak) 和服务器句柄 (File Descriptor) 耗尽的关键。
             */
            try {
                // 判断信道是否已实例化且处于开启状态，安全关闭
                if ($channel !== null && $channel->is_open()) {
                    $channel->close();
                }
                // 判断连接是否已实例化且处于连接状态，安全关闭
                if ($connect !== null && $connect->isConnected()) {
                    $connect->close();
                }
            } catch (\Exception $e) {
                // 回收资源时的二次异常只记录日志，坚决不能向外抛出，否则会覆盖主干业务的响应状态。
                Log::error("回收 RabbitMQ TCP 连接资源时发生异常: " . $e->getMessage());
            }
        }
    }

    public function test2()
    {
        // 提前初始化连接和信道变量，避免在 catch 或 finally 块中出现未定义错误
        $connect = null;
        $channel = null;
        $processedCount = 0; // 记录本次请求成功处理的消息数量

        try {
            $connect = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
            $channel = $connect->channel();

            // 1. 声明队列（保持幂等）
            $channel->queue_declare('hello', false, false, false, false);

            /**
             * 2. 核心优化：设置 QoS (Quality of Service - 公平分发)
             * * 必须在 basic_consume 之前调用。
             * @param int|null $prefetch_size 预取消息的最大字节数（通常不限制，填 null）
             * @param int $prefetch_count 预取数量。这里设为 1，意思是：在这个消费者确认(ACK)上一条消息之前，RabbitMQ 不要再给它推新消息了。这能有效防止服务器内存被打爆。
             * @param bool|null $a_global 是否全局应用（通常设为 null 或 false）
             */
            $channel->basic_qos(null, 1, null);

            /**
             * 3. 优化回调函数写法
             * * 使用类型提示增强代码健壮性
             */
            $callback = function (AMQPMessage $msg) use (&$processedCount) {
                // 生产环境严禁使用 print_r，改用日志记录
                Log::info("RabbitMQ 收到并处理消息: " . $msg->getBody());

                // 模拟业务处理（比如写入数据库等）...
                // ...

                /**
                 * 4. 核心优化：更优雅的手动 ACK 写法
                 * * 替代旧版的 $msg->delivery_info['channel']->basic_ack(...)
                 * 这会通知 RabbitMQ：“这件消息我已成功处理，你可以把它从队列里彻底删除了”。
                 */
                $msg->ack();

                // 记录处理数量
                $processedCount++;
            };

            /**
             * 5. 消费配置
             * * @param bool $no_ack 设为 false，开启手动应答（极其重要）
             */
            $channel->basic_consume('hello', '', false, false, false, false, $callback);

            Log::info("RabbitMQ 消费监听已注册，准备单次拉取...");

            /**
             * 6. 核心优化：防阻塞的 Web 消费控制
             * * 不使用死循环，而是尝试等待并获取一次消息，设置严格的超时时间（如 2 秒）。
             */
            $channel->wait(null, true, 2);

            return response()->json([
                'status' => 'success',
                'message' => "成功处理了 {$processedCount} 条消息",
            ]);

        }catch (AMQPTimeoutException $e) {
            // 这是预期内的超时（队列里没有消息了）
            return response()->json([
                'status' => 'success',
                'message' => "操作完成，当前队列为空。共处理了 {$processedCount} 条消息"
            ]);
        }catch (\Exception $e) {
            Log::error("RabbitMQ 连接/消费发生致命错误: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => '内部消息服务异常，请稍后重试'
            ], 500);
        } finally {
            /**
             * 7. 核心优化：必须放在 Finally 中的资源回收
             * * 保证无论正常结束还是抛出异常，TCP 连接和信道都能被安全释放
             */
            try {
                if ($channel !== null && $channel->is_open()) {
                    $channel->close();
                }
                if ($connect !== null && $connect->isConnected()) {
                    $connect->close();
                }
            } catch (\Exception $e) {
                Log::error("回收 RabbitMQ TCP 连接资源时发生异常: " . $e->getMessage());
            }

        }
    }
}
