<?php
/**
 * 生产者代码：发送广播消息 3
 * 在这个脚本里，你会发现一个重大改变：生产者不再声明队列了。因为它只负责对着大喇叭（交换机）喊话，至于有多少人（队列）在听，它根本不在乎。
 *
 * 发布/订阅模式（Publish/Subscribe）与交换机（Exchange）。
 *
 * 在上一课的“工作队列”中，一条消息只能被一个消费者拿到。但在真实的业务场景中，我们经常遇到这样的情况：用户下了一个订单，我们需要同时做到两件事：
 *
 * 把订单数据写入数据库。
 *
 * 给用户发一条“下单成功”的短信或邮件。
 *
 * 如果你用原来的模式，消息被数据库 Worker 拿走，短信 Worker 就收不到了。为了让一条消息被多个独立的消费者同时收到，我们就必须引入 RabbitMQ 中最核心的组件：交换机（Exchange）。
 *
 * 核心概念：交换机 (Exchange) 与 绑定 (Binding)
 * RabbitMQ 的完整思想是：生产者从不直接发送任何消息给队列。生产者只负责把消息发给“交换机”，由交换机根据规则决定把消息投递给哪些队列。这就好比你把信交给邮局（交换机），邮局按规则分发到不同的信箱（队列）。
 *
 * 在这个基础的“发布/订阅”模型中，我们将使用最简单的交换机类型：fanout（广播模式）。它不讲任何道理，只要有队列和它绑定，它就会把收到的消息无脑复制一份发过去。
 *
 */

require_once __DIR__ .'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = null;
$channel = null;

try {
    // 1. 建立 TCP 连接和信道
    $connection = new AMQPstreamConnection('host.docker.internal', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    /**
     * 2. 声明交换机 (Exchange)
     * 【企业级架构核心】：不再声明 Queue，而是声明 Exchange
     * 参数 1：exchange 名称 -> 我们起名叫 'logs'（日志广播）
     * 参数 2：type 交换机类型 -> 'fanout'（广播/扇出模式）。它会把消息发送给所有绑定到它身上的队列。
     * 参数 3：passive (被动模式) -> false
     * 参数 4：durable (持久化) -> false (本节课重点学路由，暂不开启持久化)
     * 参数 5：auto_delete (自动删除) -> false
     */
    $channel->exchange_declare('logs', 'fanout', false, false, false);

    // 接收命令行参数作为广播内容
    $data = implode(' ', array_slice($argv, 1));
    if (empty($data)) {
        $data = "info: Hello World! 这是一条全站广播消息！";
    }

    // 3. 构造消息对象
    $msg = new AMQPMessage($data);

    /**
     * 4. 发布消息到交换机
     * 【关键改变】：
     * 参数 2 变成了我们刚刚声明的交换机名字 'logs'。
     * 参数 3（路由键 routing_key）留空，因为对于 fanout 类型的交换机来说，路由键是没有意义的，它反正都要全部分发。
     */
    $channel->basic_publish($msg, 'logs');

    echo " [x] 成功广播消息: '{$data}'\n";

}catch (\Exception $e){
    echo " [!] 广播失败: " . $e->getMessage() . "\n";
} finally {
    // 5. 优雅关闭资源
    if($channel != null && $channel->is_open()){
        $channel->close();
    }

    if($connection != null && $connection->isConnected()){
        $connection->close();
    }
}
