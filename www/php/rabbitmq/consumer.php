<?php
//消费者代码 1
require_once __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// 1. 建立连接 (和生产者一模一样)
$connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
// 2. 创建信道
$channel = $connection->channel();

// 3. 再次声明队列！
// 重点建议：为什么生产者声明了，消费者还要声明？
// 答案：因为我们无法确定是生产者先启动，还是消费者先启动。如果消费者先启动，去监听一个不存在的队列会直接报错崩溃。所以两边都声明是最稳妥的做法。
$channel->queue_declare('hello', false, false, false, false);

echo " [*] 等待接收消息。按 CTRL+C 退出程序。\n";

// 4. 定义一个回调函数 (Callback)
// 当 RabbitMQ 发现队列里有消息时，就会自动把消息传给这个函数来处理
$callback = function ($msg) {
    // $msg->body 就是我们在生产者那边装进去的字符串
    echo ' [x] 接收到消息: ', $msg->body, "\n";
};

// 5. 告诉 RabbitMQ 开始消费消息
// 参数1：queue (队列名称) -> 'hello'
// 参数2：consumer_tag (消费者标签) -> 留空，让系统自动生成一个随机字符串
// 参数3：no_local (不接收本机消息) -> false，通常不用管
// 参数4：no_ack (自动确认) -> true，意思是 RabbitMQ 只要把消息发给 PHP，不管 PHP 处理完没有，就立刻从队列里删掉这条消息（进阶课会重点讲把它改成 false）
// 参数5：exclusive (排他消费者) -> false，允许多个消费者同时监听这个队列
// 参数6：nowait (不等待返回) -> false
// 参数7：callback (回调函数) -> 传入上一步定义的 $callback 闭包
$channel->basic_consume('hello', '', false, true, false, false, $callback);
// 6. 开启死循环，阻塞等待消息到来
// 只要信道处于“正在消费”的状态，就一直等待。
// 企业级规范建议：如果在旧版扩展，这里可能用 while(count($channel->callbacks))。现在推荐用 is_consuming()。
try {
    while($channel->is_consuming()) {
        // wait() 会挂起 PHP 进程，不消耗 CPU，直到有新消息进入队列触发 callback
        $channel->wait();
    }
}catch (\Exception $e){
    echo "\n [!] 消费者意外退出: " . $e->getMessage() . "\n";
} finally{
    // 7. 关闭资源 (当你在终端按下 Ctrl+C 时，可能没法走到这里，生产环境中我们用 Supervisor 和信号处理来优雅退出，以后讲)
    $channel->close();
    $connection->close();
}
