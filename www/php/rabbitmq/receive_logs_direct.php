<?php
/**
 * 消费者代码：多重绑定监听特定日志 4
 * 你可以启动两个这个脚本的实例，给它们传入不同的命令行参数，让它们监听不同的日志级别。
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// 1. 建立基础连接并开启信道
$connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 2. 再次声明 Direct 交换机，确保上下游拓扑结构的一致性与高可用
$channel->exchange_declare('direct_logs','direct', false, false, false);

// 3. 声明一个由 RabbitMQ 自动命名的、排他的临时队列
list($queueName, ,) = $channel->queue_declare("", false, false, true, false);

// 4. 解析命令行传入的监听级别（消费者想要关注的 Routing Key）
$severities = array_slice($argv, 1);
if (empty($severities)) {
    // 如果没有指定监听级别，向标准错误输出提示，并以非 0 状态码退出程序
    file_put_contents('php://stderr', "用法提示: php $argv[0] [info] [warning] [error]\n");
    exit(1);
}

/**
 * 5. 核心逻辑：循环绑定队列与交换机
 * 【企业级拓扑设计】：一个队列可以与同一个交换机进行【多次绑定】，每次使用不同的 Routing Key。
 * 遍历我们传入的级别（如同时传入 'warning' 和 'error'），将它们逐个绑定到当前队列上。
 */
foreach ($severities as $severity) {
    // 参数1: 刚才声明的临时队列名
    // 参数2: 目标交换机 'direct_logs'
    // 参数3: 绑定的路由键 ($severity)
    $channel->queue_bind($queueName, "direct_logs", $severity);
}

echo " [*] 队列 {$queueName} 已启动，正在监听级别: " . implode(', ', $severities) . "。按 CTRL+C 退出。\n";

// 6. 编写收到消息后的业务处理回调闭包
$callback = function (AMQPMessage $msg) {
    // $msg->delivery_info['routing_key'] 可以动态获取当前这条消息是以什么路由键派发过来的
    $routingKey = $msg->delivery_info['routing_key'];
    echo " [x] 触发处理 -> 级别:[{$routingKey}] 内容:", $msg->body, "\n";
};

// 7. 注册消费者（注意 7 个参数的严谨顺序，关闭自动 ACK，真实业务中应处理完再手动 $msg->ack()）
$channel->basic_consume($queueName, '', false, true, false, false, $callback);

// 8. 阻塞主进程，持续监听底层 Socket 流
try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} catch (\Exception $e) {
    echo "\n [!] 消费者运行中断: " . $e->getMessage() . "\n";
} finally {
    // 清理连接底座
    $channel->close();
    $connection->close();
}

/**
 * 为了看到精妙的路由效果，你需要打开 三个终端窗口：
 *
 * 终端 1（模拟报警系统）：只接收 error 级别的日志
 * php receive_logs_direct.php error
 *
 * 终端 2（模拟磁盘存储系统）：接收所有级别的日志
 * php receive_logs_direct.php info warning error
 *
 * 终端 3（生产者）：动态发送不同级别的日志
 * php emit_log_direct.php error "数据库连接超时！"
 * php emit_log_direct.php info "新用户注册成功"
 * php emit_log_direct.php warning "磁盘空间不足 80%"
 *
 * 你会神奇地发现，终端 1 的报警系统只打印了那条 error 消息，而终端 2 的存储系统把三条消息全收到了！
 *
 * 面试总结
 * 如果面试官考察你对消息分发策略的理解：“在 RabbitMQ 中，除了全员广播，如何将特定的消息精确投递给特定的服务群组？”
 *
 * 详细通俗易懂版
 * 这就好比寄快递时写邮政编码。广播模式（fanout）是发传单，所有人都能收到。直连模式（direct）则是写信。
 * 生产者寄信时必须在信封上写好邮编（Routing Key），比如写上 error。消费者在邮局租信箱时，也要登记自己只收哪个邮编的信，而且一个信箱可以登记多个邮编（多次绑定）。邮局的交换机一收到信，就会严格对比邮编，只有邮编一字不差对上了，才会把信塞进对应的信箱里。这样就做到了绝不打扰不需要该消息的服务。
 *
 * 专业版 (架构视角)
 * 针对高内聚低耦合的精确投递诉求，我会采用 Direct Exchange 结合 Routing Key 的拓扑模型。
 *
 * 路由匹配机制：Direct 交换机的核心算法是 Exact Match（绝对匹配）。消息发布的 Routing Key 必须与 Queue 绑定的 Binding Key 在字符串层面上完全相等，才会触发内部的投递机制。
 *
 * 拓扑复用能力：在代码落地时，一个 Queue 允许通过多条 queue_bind 指令关联到同一 Exchange 的多个 Binding Key 上，形成类似 OR 的逻辑关系（如同时监听 warning 和 error）。
 *
 * 架构解耦价值：该模式将路由策略的控制权交还给了 Broker 层。上层业务代码彻底摆脱了冗长的 if-else 或 switch 逻辑分发。即使未来新增了独立的下游微服务，也只需在下游声明新的 Binding Key 即可无缝接入数据流，无需触碰核心发布者的代码，严格遵循了开闭原则（OCP）。
 */