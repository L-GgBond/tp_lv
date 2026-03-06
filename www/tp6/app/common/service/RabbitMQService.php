<?php

namespace app\common\service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use think\facade\Log;

/**
 * Class RabbitMQService
 * 企业级 RabbitMQ 生产者基础服务层
 * @package app\common\service
 */
class RabbitMQService
{
    /**
     * @var AMQPStreamConnection|null 保持底层 TCP 连接单例
     */
    protected static ?AMQPStreamConnection $connection = null;

    /**
     * 获取单例连接 (节约连接开销)
     * @return AMQPStreamConnection
     * @throws \Exception
     */
    protected static function getConnection(): AMQPStreamConnection
    {
        if(self::$connection == null || !self::$connection->isConnected()) {
            // 从 ThinkPHP config 中读取配置
            $config = config('rabbitmq');
            self::$connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost']
            );
        }

        return self::$connection;
    }


    /**
     * 投递消息到 Topic 交换机
     *
     * @param string $exchange 交换机名称
     * @param string $routingKey 路由键
     * @param array $payload 业务数据载荷
     * @return bool
     */
    public static function publishTopic(string $exchange, string $routingKey, array $payload): bool
    {
        $channel = null;

        try {
            $connection = self::getConnection();
            // 在当前连接上开启信道
            $channel = $connection->channel();

            // 声明交换机：确保交换机存在（被动模式设为 false，持久化设为 true）
            $channel->exchange_declare($exchange, 'topic', false, true, false);

            // 将数组转为 JSON 字符串，保证跨语言通用性
            $messageBody = json_encode($payload, JSON_UNESCAPED_UNICODE);

            // 构造消息体，delivery_mode = 2 表示消息持久化
            $msg = new AMQPMessage($messageBody,[
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type'  => 'application/json'
            ]);

            // 发送消息
            $channel->basic_publish($msg, $exchange, $routingKey);

            return true;
        }catch (\Exception $e){
            // 记录严重异常日志，不抛出异常以防阻塞主干业务
            Log::error("MQ消息投递失败: [{$exchange}] {$e->getMessage()}", ['payload' => $payload]);
            return false;
        }finally{
            // 严谨规范：必须关闭信道，释放资源
            if ($channel && $channel->is_open()) {
                $channel->close();
            }
        }
    }
}