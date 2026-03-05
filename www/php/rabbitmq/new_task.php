<?php
/**
 * 生产者代码：发送持久化任务 (new_task.php) 2
 *
 * 在真实的生产环境中，我们不可能只启动一个进程去慢慢消费消息。通常，我们会启动几十个甚至上百个 Worker 进程（消费者）来共同分担海量的任务请求。
 *
 * 这就是“工作队列”（也叫任务队列）的核心思想：多个消费者共同监听同一个队列，队列里的每一条消息，只会被其中一个消费者拿到并处理（竞争消费）。
 *
 * 在这个阶段，企业级架构最关心的是三个致命问题：
 *
 * 消费者宕机了：如果分配给 Worker A 的任务还没处理完，Worker A 的进程挂了，任务是不是就丢了？（解决：手动 ACK）
 *
 * MQ 宕机了：如果 RabbitMQ 服务器突然重启，排队中的任务是不是全丢了？（解决：队列与消息持久化）
 *
 * 负载不均衡：如果 Worker A 机器性能差，Worker B 性能好，MQ 却傻傻地平均分配任务，导致 A 撑死、B 闲死怎么办？（解决：公平分发 QoS）
 *
 */
require_once __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = null;
$channel = null;

try {
    // 1. 建立 TCP 长连接
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    /**
     * 2. 声明工作队列
     * 【企业级规范 - 防丢失核心 1】：队列持久化
     * 第三个参数 $durable 必须设为 true！这表示 RabbitMQ 会把这个队列的元数据存入磁盘。
     * 即使 RabbitMQ 服务崩溃重启，这个队列依然存在。
     */
    $channel->queue_declare('task_queue',false,true,false,false);

    // 接收命令行参数作为任务内容，如果没有则使用默认文本
    $data = implode(' ', array_slice($argv, 1));
    if (empty($data)) {
        $data = "Hello World! 这是一个默认的耗时任务...";
    }

    /**
     * 3. 构造消息对象
     * 【企业级规范 - 防丢失核心 2】：消息持久化
     * 仅仅队列持久化是不够的，如果消息在内存中，宕机依然会丢。
     * 必须设置 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT (其值为 2)。
     * ⚠️ 提示：持久化会牺牲一部分写入性能（因为要刷盘），但在订单、支付等核心业务中，这是必须付出的代价。
     */
    $msg = new AMQPMessage(
        $data,
        array(
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        )
    );

    // 4. 将消息发布到默认交换机，路由键为我们的持久化队列名 'task_queue'
    $channel->basic_publish($msg, '', 'task_queue');

    echo " [x] 成功派发任务: '{$data}'\n";

}catch (\Exception $e){
    echo " [!] 派发任务失败: " . $e->getMessage() . "\n";
} finally {
    // 5. 兜底资源清理，防止 TCP 句柄泄露
    if ($channel != null && $channel->is_open()){
        $channel->close();
    }

    if($connection != null && $connection->isConnected()){
        $connection->close();
    }
}












