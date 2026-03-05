<?php
/**
 * 消费者代码：集群竞争与手动确认 2
 *
 * 这个脚本你可以同时在终端开启 3 个或 5 个窗口运行。你会发现 RabbitMQ 会非常智能地把任务分发给它们。
 * 要保证消息不丢，得在三个环节上“上双保险”。
 * 第一，防止机器重启丢数据。我们在代码里必须给“队列”和“消息”都打上持久化标记（durable=true 和 delivery_mode=2），这样 RabbitMQ 收到消息后会立刻存到硬盘里，就算机房断电，重启后订单还在。
 * 第二，防止处理一半死机丢数据。我们绝不能让 RabbitMQ 发完消息就立刻删掉（关闭自动 ACK），必须等我们的 PHP Worker 把订单真正写进 MySQL 数据库后，再手动发个 ack() 告诉 MQ“我搞定了，你可以删了”。如果 PHP 中途崩溃了，MQ 收不到 ack，就会把订单重新分给下一个活着的 Worker。
 * 第三，防止机器被撑死。我们会加一句 basic_qos(1) 限流，意思是“我干完一单，你再给我派下一单”，让干活快的机器多干点，避免服务器内存爆掉。
 *
 */
require_once __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPstreamConnection('host.docker.internal', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 1. 声明同样的持久化队列（再次强调：防报错的防御性编程）
$channel->queue_declare('task_queue', false, true, false, false);

echo " [*] 等待分配任务。按 CTRL+C 退出程序。\n";

/**
 * 2. 核心回调函数（处理具体业务逻辑）
 * 使用强类型声明，确保传入的是 AMQPMessage 对象
 */
$callback = function (AMQPMessage $msg) {
    echo ' [x] 接收到任务: ', $msg->body, "\n";

    // 模拟业务处理的耗时操作（通过统计字符串中的点 '.' 来决定沉睡几秒）
    $sleepSeconds = substr_count($msg->body, '.');
    sleep($sleepSeconds);

    echo " [x] 任务处理完成！耗时 {$sleepSeconds} 秒。\n";

    /**
     * 【企业级规范 - 防丢失核心 3】：手动 ACK (Acknowledgment)
     * 任务执行完之后，显式地告诉 RabbitMQ：“我已经成功处理完了，你可以把这条消息从磁盘/内存里删除了”。
     * ⚠️ 警告：如果不写这一句，消息会一直处于 Unacked 状态，一旦这个 PHP 进程断开，消息会重新回队，导致重复消费！
     */
    $msg->ack();
};

/**
 * 3. 消费者限流 (Quality of Service - 公平分发)
 * 【企业级规范 - 防雪崩核心】
 * 默认情况下，RabbitMQ 会把队列里现有的消息一股脑全推给消费者。
 * 参数 basic_qos(null, 1, null) 告诉 RabbitMQ：
 * “在这个 Worker 回复 ACK 确认上一条消息之前，不要再给它派发新的消息了！”
 * 这样，性能好的机器会处理更多的任务，性能差的机器也不会被压垮。
 */
$channel->basic_qos(null, 1, null);

/**
 * 4. 注册消费者
 * 【关键修改】：第四个参数 $no_ack (自动应答) 必须设为 false！
 * 设为 false 表示关闭自动确认，开启我们在代码里写的手动 ACK 机制。
 */
$channel->basic_consume('task_queue', '', false, false, false, false, $callback);

// 5. 阻塞轮询，等待任务
try {
    // is_consuming() 检查信道是否还在监听
    while($channel->is_consuming()){
        // 挂起进程，不占用 CPU，等待网络流中的新消息
        $channel->wait();
    }

}catch (\Exception $e){
    echo "\n [!] Worker 异常退出: " . $e->getMessage() . "\n";
} finally {
    // 优雅关闭
    $channel->close();
    $connection->close();
}