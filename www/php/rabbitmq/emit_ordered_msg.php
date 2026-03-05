<?php
/**
 * 基于订单 ID 的 Hash 路由投递 7
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = null;
$channel = null;

try {
    // 1. 建立长连接
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    // 2. 声明一个 Direct 类型的核心交换机，专门处理顺序消息
    $exchangeName = 'order_sequential_exchange';
    $channel->exchange_declare($exchangeName, 'direct', false, true, false);

    // 3. 模拟同一个订单产生的 3 条按时间先后触发的消息
    $orderId = 10086;
    $messages = [
        ['type' => '1_CREATE', 'content' => '订单创建'],
        ['type' => '2_PAY',    'content' => '订单支付'],
        ['type' => '3_SEND',   'content' => '订单发货'],
    ];

    /**
     * 4. 核心逻辑：Hash 运算决定路由键
     * 【架构亮点】：利用 crc32 将字符串/数字转为整型，对队列总数（假设我们有 4 个顺序子队列）取模。
     * 这样 orderId=10086 的所有消息，计算出的 Hash 值永远是一样的（比如等于 2）！
     */
    $queueCount = 4;
    $hashValue = crc32((string)$orderId) % $queueCount;
    print_r($hashValue);

    // 动态生成路由键，例如 'order_queue_2'
    $routingKey = "order_queue_" . $hashValue;

    // 5. 循环发送消息
    foreach ($messages as $msgData) {
        $payload = json_encode([
            'order_id' => $orderId,
            'type'     => $msgData['type'],
            'content'  => $msgData['content']
        ]);

        // 【严格规范】：必须持久化，防止宕机丢消息打断顺序链
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

        // 投递到指定的 Hash 子队列
        $channel->basic_publish($msg, $exchangeName, $routingKey);
        echo " [x] 已投递 [{$msgData['type']}] 到路由: {$routingKey}\n";
    }

}catch (\Exception $e){
    echo " [!] 发送异常: " . $e->getMessage() . "\n";
}finally{
    if ($channel && $channel->is_open()) $channel->close();
    if ($connection && $connection->isConnected()) $connection->close();
}