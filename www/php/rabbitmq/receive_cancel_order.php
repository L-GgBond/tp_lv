<?php
/**
 * 消费者代码：监听死信队列执行取消 6
 * 这个脚本只负责监听终点站（真正的取消队列）。它根本不需要知道延迟是怎么发生的，它只知道：只要消息到了我这里，就说明绝对已经超时了。
 *
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 防御性声明：确保死信交换机和真正的处理队列存在
$channel->exchange_declare('order_dlx', 'direct', false, true, false);
$channel->queue_declare('order_cancel_queue', false, true, false, false);
$channel->queue_bind('order_cancel_queue', 'order_dlx', 'cancel_routing_key');

echo " [*] 订单取消系统已启动，正在监听超时死信队列。按 CTRL+C 退出。\n";

// 核心业务回调
$callback = function (AMQPMessage $msg) {
    $data = json_decode($msg->body, true);

    echo "--------------------------------------------------\n";
    echo " [!] 收到超时死信，开始处理取消逻辑...\n";
    echo " [x] 订单编号: " . $data['order_id'] . "\n";
    echo " [x] 当前时间: " . date('Y-m-d H:i:s') . " (验证是否正好过去 10 秒)\n";

    // TODO: 这里编写真实的业务逻辑
    // 1. 查询数据库，确认订单确实还没支付
    // 2. 如果未支付，更新订单状态为已取消
    // 3. 调用库存服务，恢复商品库存

    // 【大厂规范】：一定要手动 ACK！处理完了才能删掉死信。
    $msg->ack();
};

// 消费者限流 QoS，防止瞬间涌入大量死信压垮 PHP 进程
$channel->basic_qos(null, 1, null);

// 监听【真正的处理队列】(order_cancel_queue)，关闭自动确认(no_ack=false)
$channel->basic_consume('order_cancel_queue', '', false, false, false, false, $callback);

try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} catch (\Exception $e) {
    echo "\n [!] 消费者异常退出: " . $e->getMessage() . "\n";
} finally {
    $channel->close();
    $connection->close();
}

/**
 * 测试实战演示
 * 为了看到极度舒适的“定时爆炸”效果：
 *
 * 先启动消费者：php receive_cancel_order.php
 *
 * 运行一次生产者：php emit_delay_order.php
 *
 * 你会看到，生产者打印出的当前时间是（假设）：15:00:00。
 * 整整 10 秒钟内，消费者这边没有任何动静。
 * 到了 15:00:10，消费者终端瞬间打印出收到死信的消息！一秒不差！
 *
 * 面试总结
 * 如果面试官问：“如何优雅地处理电商系统中的订单超时取消？为什么要用死信队列来实现？”这就是你展示架构深度的最佳时机：
 *
 * 详细通俗易懂版
 * 如果用定时任务（Cron）去扫数据库，就像是保安每隔一小时去各个房间敲门查房，不仅浪费体力（消耗数据库性能），而且如果客人刚好在保安走后一分钟超时，要等快一个小时保安下一次查房才能发现（延迟高）。
 *
 * 使用死信队列，就像给每个订单装了一个“10秒定时炸弹”。
 * 我们建一个没人看管的“小黑屋队列”，告诉 RabbitMQ：凡是进了这个小黑屋的消息，只要呆满 10 秒钟就会“老死”，你必须马上把它的“尸体”扔进旁边的“停尸房交换机（死信交换机）”。
 * 我们的 PHP 消费者只需要在停尸房守着就行了。只要有订单被扔过来，就说明它绝对已经呆满 10 秒超时了。直接拿过来取消订单、回滚库存。这种做法一秒钟都不会差，而且完全不给数据库增加任何查询负担。
 *
 * 专业版 (架构视角)
 * 针对高并发场景下的延时任务治理（如超时关单、延迟重试），传统的基于 DB 的轮询补偿机制存在严重的扫表性能瓶颈与时间精度损耗。我在架构设计中通常采用 RabbitMQ 的 TTL (Time-To-Live) 结合 DLX (Dead Letter Exchange) 机制 来构建异步延时调度中心。
 *
 * 生命周期流转：通过实例化 AMQPTable，为业务缓冲队列配置 x-message-ttl 属性。由于该队列刻意不分配 Consumer 实例，消息到达阈值后会触发底层的 Eviction（驱逐）机制，转变为 Dead Letter。
 *
 * 拓扑重定向：利用缓冲队列中配置的 x-dead-letter-exchange 和 x-dead-letter-routing-key，Broker 会在 Broker 层级原生地将死信重定向至业务死信交换机，最终路由至执行取消逻辑的 Terminal Queue。
 *
 * 架构优势与注意点：该方案实现了毫秒级的调度精度，且彻底释放了 DB 的轮询 I/O 压力。但需要注意队头阻塞问题（Head-of-Line Blocking）：由于 RabbitMQ 的 TTL 过期判断仅针对队头元素，如果给不同消息设置不同的 TTL 并投入同一队列，后发但早过期的消息会被队头阻塞。因此在严谨的设计中，同一延迟队列内的消息 TTL 必须保持严格一致，或者改用官方的 rabbitmq_delayed_message_exchange 插件实现真正的按时间轮（Time Wheel）分发。
 */