<?php
/**
 * 生产者代码：发送带级别标签的日志 4
 * 在上一课中，我们使用了 fanout（广播）交换机，它就像一个毫无感情的扩音器，把消息塞给所有绑定它的队列。但这在真实的企业架构中往往不够用。
 *
 * 设想一个经典的微服务场景：日志收集系统。
 * 系统会产生大量的日志（info、warning、error）。我们希望：
 *
 * 把所有级别的日志都存入本地磁盘队列（供后续数据分析）。
 *
 * 把只有 error 级别的致命错误日志发送给报警队列（触发钉钉或邮件报警）。
 *
 * 如果用 fanout，报警队列会收到海量的 info 垃圾信息，这显然是不合理的。 为了实现这种“精准分类推送”，我们必须引入 direct（直连）交换机 和 路由键（Routing Key） 的精确匹配。
 *
 * 核心概念：路由键 (Routing Key) 的精准打击
 * 在 direct 模式下，消息的流转规则非常严谨：
 * 生产者发消息时，必须给消息贴上一个标签（这就是 Routing Key，比如 error）。
 * 消费者在绑定队列和交换机时，也必须声明自己想要的标签（也是 Routing Key）。
 * 交换机收到消息后，会严格对比标签。只有当消息的 Routing Key 和队列绑定的 Routing Key 完全一模一样时，消息才会被投递过去。
 * 我们直接用企业级的严谨代码来实战这个日志分发系统。
 *
 * 在这个脚本中，我们通过命令行的参数来动态指定日志的级别（Routing Key）和日志内容。
 */
require_once __DIR__ .'/vendor/autoload.php';

// 导入所需的连接类与消息体类
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// 初始化变量，防止异常分支中抛出未定义警告
$connection = null;
$channel = null;

try {
    // 1. 实例化 TCP 长连接（生产环境中强烈建议配置心跳检测 keepalive 机制）
    $connection = new AMQPStreamConnection('host.docker.internal', 5672, 'guest', 'guest');
    // 2. 在物理连接上复用逻辑信道
    $channel = $connection->channel();

    /**
     * 3. 声明核心组件：Direct 交换机
     * 【架构升级】：将类型从上一课的 'fanout' 改为 'direct'。
     * 参数1: 交换机名称 'direct_logs'
     * 参数2: 交换机类型 'direct' (直连模式，要求路由键绝对匹配)
     * 参数3/4/5: 被动模式(false)、持久化(false)、自动删除(false)
     */
    $channel->exchange_declare('direct_logs','direct', false, false, false);

    // 4. 接收命令行参数处理
    // 获取第一个参数作为日志级别（也就是我们的 Routing Key），默认是 'info'
    $severity = isset($argv[1]) && !empty($argv[1]) ? $argv[1] : 'info';

    // 获取后续参数拼装成完整的日志内容，如果没有则使用默认字符串
    $messageBody = implode(' ', array_slice($argv, 2));
    if (empty($messageBody)) {
        $messageBody = "系统运行正常 (Hello World!)";
    }

    // 5. 构造标准的 AMQP 消息对象
    $msg = new AMQPMessage($messageBody);

    /**
     * 6. 精准投递消息
     * 【企业级路由核心】：
     * 参数2: 指定目标交换机 'direct_logs'
     * 参数3: 传入 $severity 作为 Routing Key（如 'info', 'warning', 'error'）
     * 交换机会根据这个 Key 寻找精确匹配的下游队列。
     */
    $channel->basic_publish($msg, 'direct_logs', $severity);

    // 终端回显，方便观察发送状态
    echo " [x] 成功发送日志 - 级别:[{$severity}] 内容:'{$messageBody}'\n";

}catch (\Exception $e){
    // 捕获不可预见的网络震荡或协议异常
    echo " [!] 日志发送失败: " . $e->getMessage() . "\n";
} finally {
    // 7. 强制资源回收（防御性编程）
    if ($channel !== null && $channel->is_open()) {
        $channel->close();
    }
    if ($connection !== null && $connection->isConnected()) {
        $connection->close();
    }
}
