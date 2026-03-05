<?php
/**
 * 消费者代码：生成临时队列并绑定交换机 3
 *
 * 为了验证广播效果，你可以开两个或多个终端窗口同时运行这个消费者代码。你会发现，它们都能收到同一条广播消息！
 */
require_once __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 1. 声明同一个交换机（防御性编程：防止消费者先启动时找不到交换机）
$channel->exchange_declare('logs','fanout', false, false, false);

/**
 * 2. 声明一个临时、排他的专属队列
 * 【企业级技巧】：在广播模式下，每个消费者都需要一个属于自己的、全新的空队列。
 * 如果我们不给队列命名（传空字符串 ""），RabbitMQ 会自动为我们生成一个随机名字（如 amq.gen-JzTY20BRgKO-HjmUJj0wLg）。
 * 参数 4 `$exclusive = true` (排他性)：极其重要！意思是只要这个 PHP 进程断开连接，这个随机队列就会被自动删除，不留垃圾。
 * * queue_declare 返回一个数组，第一个元素就是系统生成的队列名。
 */
list($queueName, , ) = $channel->queue_declare('', false, false, true, false);

/**
 * 3. 将队列与交换机【绑定】 (Binding)
 * 核心逻辑：告诉交换机 'logs'，“请把你收到的消息，也往我这个队列 ($queueName) 里抄送一份！”
 */
$channel->queue_bind($queueName, 'logs');

echo " [*] 临时队列 {$queueName} 已就绪，正在监听全站广播。按 CTRL+C 退出。\n";

// 4. 定义回调函数处理消息
$callback = function ($msg) {
    echo ' [x] 收到广播: ', $msg->body, "\n";
};

// 5. 开始消费我们刚刚绑定的专属临时队列
// 这里为了简化演示，暂时开启 auto_ack = true
$channel->basic_consume($queueName, '', false,true, false, false, $callback);

// 6. 挂起进程，阻塞等待
try {
    while($channel->is_consuming()) {
        $channel->wait();
    }
}catch (\Exception $e){
    echo "\n [!] 消费者异常退出: " . $e->getMessage() . "\n";
} finally {
    $channel->close();
    $connection->close();
}
