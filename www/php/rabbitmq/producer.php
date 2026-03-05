<?php
//生产者代码 1
// 引入自动加载文件，确保能加载到 php-amqplib
require_once __DIR__.'/vendor/autoload.php';

// 引入 RabbitMQ 长连接类
use PhpAmqpLib\Connection\AMQPStreamConnection;
// 引入 RabbitMQ 消息体类
use PhpAmqpLib\Message\AMQPMessage;

// 提前声明变量，避免在 catch 或 finally 中未定义报错
$connection = null;
$channel = null;

try {
    // 1. 建立到 RabbitMQ 服务器的 TCP 长连接
    // 参数说明：主机地址, 端口(默认5672), 登录用户名(默认guest), 登录密码(默认guest)
    // 建议：企业级开发中，这些参数必须从 .env 环境变量中读取，切忌硬编码
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');

    // 2. 在 TCP 连接内创建一个虚拟的信道 (Channel)
    // 解释：建立 TCP 连接是很耗资源的，AMQP 协议利用信道实现“多路复用”，所有发消息的动作都在信道里完成
    $channel = $connection->channel();

    // 3. 声明一个队列 (Queue)
    // 解释：如果队列不存在，RabbitMQ 会自动创建；如果已存在，这句代码什么都不做（幂等操作）。 幂等操作是指：无论执行一次，还是执行多次，最终结果都完全相同。换句话说：重复执行不会产生副作用。
    // 参数1：queue (队列名称) -> 我们命名为 'hello'
    // 参数2：passive (被动模式) -> false，表示如果队列不存在就创建它
    // 参数3：durable (持久化) -> false，表示存放在内存中，RabbitMQ 重启后队列会消失（后续进阶课会讲设为 true）
    // 参数4：exclusive (排他性) -> false，表示不仅限于当前连接，其他连接也能访问这个队列
    // 参数5：auto_delete (自动删除) -> false，表示哪怕没有消费者连接了，也不要自动删除这个队列
    $channel->queue_declare('hello', false, false, false, false);

    // 4. 准备要发送的消息内容
    $messageBody = "Hello World! 这是我的第一条消息！";

    // 5. 将字符串封装成标准的 AMQP 消息对象
    $msg = new AMQPMessage($messageBody);

    // 6. 发布消息到队列
    // 参数1：msg (消息对象) -> 上一步创建的 $msg
    // 参数2：exchange (交换机名称) -> 这里留空字符串，表示使用 RabbitMQ 默认的直连交换机
    // 参数3：routing_key (路由键) -> 当交换机为空时，路由键的名字就是目标队列的名字，所以填 'hello'
    $channel->basic_publish($msg, '', 'hello');

    // 在控制台打印成功提示
    echo " [x] 成功发送消息: '{$messageBody}'\n";

}catch (\Exception $e){
    // 捕获可能发生的网络异常或认证异常
    echo " [!] 发生错误: " . $e->getMessage() . "\n";
} finally {
    // 7. 优雅地关闭资源 (极其重要！)
    // 企业级规范：不管发消息是成功还是失败，最后必须关闭信道和连接，防止服务器句柄泄露
    if ($channel !== null && $channel->is_open()) {
        $channel->close(); // 关闭信道
    }

    if ($connection !== null && $connection->isConnected()) {
        $connection->close(); // 关闭连接
    }
}

/**
 * RabbitMQ 基于 AMQP 0-9-1 协议。在最基础的点对点模型中，主要涉及 Connection、Channel 和 Queue 三个核心抽象。
 *
 * 连接与多路复用：在 PHP 中，我通过 AMQPStreamConnection 建立底层的 TCP Socket 长连接。为了降低 TCP 握手开销，通过 channel() 实现多路复用，后续的声明、生产和消费全在轻量级的 Channel 作用域内完成。
 *
 * 拓扑声明的幂等性：在代码规范上，无论生产者还是消费者，都会先执行 queue_declare。这是一种防御性编程，利用了 AMQP 声明操作的幂等性，避免因组件启动顺序不确定导致 NOT_FOUND 异常。
 *
 * 资源生命周期管理：在生产者端，网络 I/O 容易引发异常，我严格遵循在 finally 块中执行 close() 进行 Graceful Shutdown，防范 Socket 句柄泄露。在消费者端，使用 CLI 常驻进程配合 wait() 阻塞读取，将进程挂起以避免 CPU 100% 的空转消耗。
 *
 */