<?php
/**
 * 消费者代码：使用通配符进行模糊订阅 5
 * 这是 topic 模式的灵魂所在。我们可以启动多个消费者，给它们分配不同的通配符规则。
 *
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// 1. 建立连接并开启信道
$connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 2. 声明同样的 Topic 交换机，保障拓扑结构的一致性
$channel->exchange_declare('topic_logs', 'topic', false, false, false);

// 3. 声明排他的临时专属队列 (进程断开即销毁)
list($queueName, ,) = $channel->queue_declare("", false, false, true, false);

// 4. 获取命令行传入的绑定规则 (可以是多个通配符规则)
$bindingKeys = array_slice($argv, 1);
if (empty($bindingKeys)) {
    // 如果没有传入规则，强行退出程序并给出典型的通配符使用示例
    file_put_contents('php://stderr', "用法提示: php $argv[0] [binding_key]\n");
    file_put_contents('php://stderr', "示例: php $argv[0] 'kern.*' '*.critical' 'sys.#'\n");
    exit(1);
}

/**
 * 5. 核心逻辑：使用通配符绑定队列与交换机
 * 【企业级拓扑设计】：将传入的规则（如 'user.#' 或 '*.error'）遍历绑定到当前队列。
 */
foreach ($bindingKeys as $bindingKey) {
    // 参数3 $bindingKey: 决定了当前队列能接收到哪些格式的层级消息
    $channel->queue_bind($queueName, 'topic_logs', $bindingKey);
}

echo " [*] 队列 {$queueName} 已就绪，正在监听规则: " . implode(', ', $bindingKeys) . "。按 CTRL+C 退出。\n";

// 6. 消息处理回调闭包
$callback = function (AMQPMessage $msg) {
    // 打印出触发该回调的实际路由键
    $routingKey = $msg->delivery_info['routing_key'];
    echo " [x] 匹配成功 -> 实际路由键:[{$routingKey}] 内容:", $msg->body, "\n";
};

/**
 * 7. 注册消费者
 * 【规范提示】：测试演示暂时开启 auto_ack (第四个参数 true)。
 * 在真实业务如资金流水处理中，务必设为 false，并在回调底部执行 $msg->ack()。
 */
$channel->basic_consume($queueName, '', false, true, false, false, $callback);

// 8. 挂起进程，阻塞轮询
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
 * 为了感受通配符的魔力，请打开三个终端窗口：
 *
 * 终端 1（模拟“全能日志系统”：接收所有以 sys. 开头的消息）：
 * php receive_logs_topic.php "sys.#"
 *
 * 终端 2（模拟“专项报警系统”：接收所有结尾是 .error 的消息）：
 * php receive_logs_topic.php "*.error"
 *
 * 终端 3（生产者：发送不同层级的消息）：
 * php emit_log_topic.php sys.cron.info "系统定时任务执行完毕"
 * (终端 1 会收到，终端 2 收不到，因为结尾不是 error)
 *
 * php emit_log_topic.php user.login.error "用户密码错误超限"
 * (终端 1 收不到，终端 2 会收到，因为以 error 结尾)
 *
 * php emit_log_topic.php sys.db.error "MySQL 连接断开！"
 * (终端 1 和终端 2 都会收到！)
 *
 *
 *
 * 面试总结
 * 如果面试官考察你对微服务事件总线的理解：“在庞大的微服务集群中，有很多服务都会抛出事件。如何设计一套既能精准订阅，又能模糊匹配的消息分发路由？”
 *
 * 详细通俗易懂版
 * 这时候用主题模式（Topic）最完美。它就像是带了“正则表达式”的信件分发系统。
 * 我们规定发信时，信封上的标签必须是用点隔开的词，比如“用户组.登录.失败”。
 * 消费者在租信箱时，可以用特殊的符号来占位。* 是一对一占位，# 是无限占位。比如负责风控的机器，只需要绑定一个 *.登录.失败，不管前面是“用户组”还是“商家组”，只要是登录失败的信，它全能收到。而负责全盘监控的机器，只要绑定一个 #，就能像广播一样收到所有人的所有信件。这种设计让我们加新功能时，连老代码都不用碰。
 *
 * 专业版 (架构视角)
 * 针对高维度的事件路由需求，我会采用 Topic Exchange 构建企业级的事件总线（Event Bus）。
 *
 * 分层路由语义：在 Topic 模式下，Routing Key 被设计为由 . 分隔的语义化层级字符串（如 domain.entity.action）。
 *
 * 正则拓扑绑定：Consumer 端在发起 queue_bind 时，支持引入 *（严格匹配单级词组）和 #（零或多级词组模糊匹配）作为 Binding Key。这种基于 AST 树的路由算法，不仅支持精准路由，更支持高维度的聚合订阅。
 *
 * 架构解耦与演进：这种多态路由机制极大地提升了系统的可维护性。例如，当业务扩充需要新增一个 payment.refund.error 链路时，原本绑定了 #.error 的监控系统会自动感知并接管该异常流，实现了生产者与消费者在业务层面的彻底解耦和零侵入式扩展。
 */