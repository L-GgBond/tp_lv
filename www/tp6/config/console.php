<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'mq:sms_worker' => \app\command\SmsWorker::class,
        'mq:points_worker' => \app\command\PointsWorker::class,
    ],
];
