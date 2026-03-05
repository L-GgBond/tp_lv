<?php
/**
 * 生产者代码：发送层级化主题消息 5
 * 在这个脚本中，我们模拟发送带有层级结构的路由键（如 auth.user.login）。
 *
 * 第五课：主题模式（Topic Exchange）与通配符高级路由！
 * 在上一课的 direct 模式中，我们实现了精准匹配（比如只收 error 日志）。但在企业级复杂业务中，命名规则往往是层级化的。
 * 假设我们的系统有很多微服务，它们发出的消息路由键长这样：
 * user.login.success（用户登录成功）
 * user.register.success（用户注册成功）
 * order.create.failed（订单创建失败）
 * order.pay.success（订单支付成功）
 * 如果用 direct 模式，一个负责“记录所有用户行为”的队列，必须手动把所有 user.xxx.xxx 的键全部绑定一遍，一旦新增了业务，还得去改绑定代码，这极度违反了开闭原则 (OCP)。
 * 为了解决这种灵活匹配的需求，RabbitMQ 提供了最强大的交换机类型：topic（主题交换机）。
 *
 * 核心概念：通配符 (Wildcards) 的降维打击
 * 在 topic 模式下，路由键必须是由**点号（.）**分隔的多个单词（最大 255 字节）。
 * 消费者在绑定队列时，可以使用两个极其强大的通配符：
 *
 *  (星号)：精确匹配一个单词。
 * 比如 user.*.success，可以匹配 user.login.success，但不能匹配 user.success。
 *
 * # (井号)：匹配零个或多个单词。
 * 比如 user.#，可以匹配 user.login.success，也可以匹配 user.update。
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = null;
$channel = null;

try {
    // 1. 建立 TCP 物理连接
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');

    // 2. 开启轻量级逻辑信道
    $channel = $connection->channel();

    /**
     * 3. 声明核心组件：Topic 交换机
     * 【架构升级】：类型改为 'topic'。这是企业级微服务事件总线 (Event Bus) 最常用的类型。
     * 参数2: 'topic' 模式，支持根据点号分隔的层级路由键进行通配符匹配。
     */
    $channel->exchange_declare('topic_logs','topic', false, false, false);

    // 4. 获取命令行传入的第一个参数作为 Routing Key (如 'sys.cron.error')
    $routingKey = isset($argv[1]) && !empty($argv[1]) ? $argv[1] : 'anonymous.info';

    // 获取后续参数拼装成日志内容
    $messageBody = implode(' ', array_slice($argv, 2));
    if (empty($messageBody)) {
        $messageBody = "这是一条默认的系统提示消息";
    }

    // 5. 构造标准的 AMQP 消息对象
    $msg = new AMQPMessage($messageBody);

    /**
     * 6. 投递消息到 Topic 交换机
     * 此时交换机会根据点号 '.' 拆分 $routingKey，并去寻找能匹配上的下游队列。
     */
    $channel->basic_publish($msg, 'topic_logs', $routingKey);

    echo " [x] 成功发送主题日志 - 路由键:[{$routingKey}] 内容:'{$messageBody}'\n";

}catch (\Exception $e){
    echo " [!] 主题日志发送失败: " . $e->getMessage() . "\n";
} finally {
    // 7. 优雅地回收底层句柄，避免网络资源干涸
    if ($channel !== null && $channel->is_open()) {
        $channel->close();
    }
    if ($connection !== null && $connection->isConnected()) {
        $connection->close();
    }
}