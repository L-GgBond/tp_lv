<?php
/**
 * 生产者代码：投递延迟订单 6
 * 这段代码是整个延迟架构的灵魂。注意看我们是如何使用 AMQPTable 为队列注入高级参数的。
 *
 * 死信队列 (DLX) 与延迟队列的高级实战！
 * 在真实的电商架构中，我们经常遇到这样的业务需求：“用户下单后，如果 30 分钟未支付，系统需要自动取消订单并释放库存”。
 * 如果用传统的 PHP 定时任务（Cron）去扫表，每分钟查一次数据库，不仅会有高达 1 分钟的延迟误差，而且在海量订单下，频繁的扫表会直接拖垮数据库性能。
 * 使用 RabbitMQ 的延迟队列是业界最标准的解决方案。但有趣的是，RabbitMQ 原生其实没有延迟队列这个功能（除非安装第三方插件）。我们是通过巧妙结合 消息过期 (TTL) 和 死信交换机 (DLX) 来“伪造”出延迟队列的。
 * 核心概念：什么是“死信”？如何变成“延迟”？
 * 一条正常的消息在什么情况下会变成“死信 (Dead Letter)”？通常有三种情况：
 * 被消费者拒绝收货：消费者调用了 nack 或 reject，并且设置了不退回原队列（requeue=false）。
 * 队列满了：队列长度达到了极限，塞不进去了。
 * 消息老死了 (TTL 过期)：消息在队列里存活的时间超过了设定的寿命（TTL, Time To Live），依然没有被任何人消费。
 * 延迟队列的终极套路：
 * 我们故意创建一个没有消费者监听的临时队列，给它设置 10 秒的寿命（TTL）。把订单消息扔进去，因为没人消费，10 秒后消息“老死”变成死信。此时，我们提前给这个临时队列绑定好一个“死信交换机 (DLX)”，RabbitMQ 就会自动把死尸（死信）转运到 DLX。DLX 再把消息路由给真正的“取消订单队列”。我们只要在这个真正的队列挂一个消费者，就能完美实现在 10 秒后准时收到消息！
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

$connection = null;
$channel = null;

try {
    // 1. 建立 TCP 连接和信道
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    /**
     * ==========================================
     * 第一步：声明【死信系统】(终点站)
     * ==========================================
     */

    // 1.1 声明死信交换机 (DLX)
    // 名字叫 'order_dlx'，类型为 'direct'，开启持久化
    $channel->exchange_declare('order_dlx', 'direct', false, true, false);

    // 1.1 声明死信交换机 (DLX)
    // 名字叫 'order_dlx'，类型为 'direct'，开启持久化
    $channel->exchange_declare('order_dlx', 'direct', false, true, false);

    // 1.3 将处理队列绑定到死信交换机，路由键为 'cancel_routing_key'
    $channel->queue_bind('order_cancel_queue', 'order_dlx', 'cancel_routing_key');

    /**
     * ==========================================
     * 第二步：声明【延迟队列】(中转站，无消费者)
     * ==========================================
     */

    // 【大企业级规范】：在 RabbitMQ 中配置队列的高级属性，必须使用 AMQPTable 对象
    $args = new AMQPTable([
        // 核心参数 1：当消息成为死信后，把它交给哪个交换机？(绑定我们在上面声明的 DLX)
        'x-dead-letter-exchange' => 'order_dlx',

        // 核心参数 2：转交时，赋予它什么新的路由键？(这样 DLX 就能准确投递给取消队列)
        'x-dead-letter-routing-key' => 'cancel_routing_key',

        // 核心参数 3：队列级别的时间寿命 (TTL)，单位是毫秒。10000 毫秒 = 10 秒。
        // 只要消息在这个队列里呆满 10 秒没人管，立刻宣判死亡！
        'x-message-ttl' => 10000
    ]);

    // 声明延迟队列，注意第六个参数传入了我们刚刚构造的 $args
    $channel->queue_declare('order_delay_queue', false, true, false, false, false, $args);

    /**
     * ==========================================
     * 第三步：生产订单消息
     * ==========================================
     */
    $orderId = "ORD_" . time() . "_" . mt_rand(1000, 9999);
    $payload = json_encode(['order_id' => $orderId, 'status' => 'unpaid']);

    // 消息持久化 (delivery_mode = 2)
    $msg = new AMQPMessage($payload, [
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
    ]);

    // 【划重点】：生产者把消息发给谁？
    // 我们直接把消息扔进【延迟队列】('order_delay_queue')，等待它自然死亡。
    // 这里使用默认的空交换机 ''，路由键也就是队列名。
    $channel->basic_publish($msg, '', 'order_delay_queue');

    echo " [x] 成功下单 [{$orderId}]，已送入延迟队列，预计 10 秒后若未支付将自动取消。\n";
    echo " [x] 当前时间: " . date('Y-m-d H:i:s') . "\n";

}catch (\Exception $e){
    echo " [!] 下单投递失败: " . $e->getMessage() . "\n";
} finally {
    if ($channel !== null && $channel->is_open()) {
        $channel->close();
    }
    if ($connection !== null && $connection->isConnected()) {
        $connection->close();
    }
}