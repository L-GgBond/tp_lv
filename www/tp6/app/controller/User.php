<?php
namespace app\controller;

use app\BaseController;
use app\common\service\RabbitMQService;
use think\facade\Log;
use think\facade\Db;
use think\facade\Request;

class User extends BaseController
{
    /**
     * 用户注册接口
     * @param Request $request
     * @return \think\response\Json
     */
    public function register(Request $request)
    {
        // 1. 获取参数
        $data = [
            'name' => Request::param('username'),
            'password' => Request::param('password'),
            'phone'    => Request::param('phone'),
        ];

        // 2. 开启 ThinkPHP 本地数据库事务
        Db::startTrans();
        try {
            // 3. 核心业务落盘 (写入 users 表)
            $userId = Db::name('users')->insertGetId([
                'name' => $data['name'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'phone'    => $data['phone'],
            ]);

            Db::commit();
        }catch (\Exception $e){
            Db::rollback();
            Log::error("注册写入 DB 失败: " . $e->getMessage());
            return json(['code' => 500, 'msg' => '注册失败']);
        }

        // 4. 组装异步事件数据 (切忌把敏感信息如密码扔进 MQ)
        $eventData = [
            'event_id' => uniqid('EVT_REG_'), // 全局防重 ID
            'user_id'  => $userId,
            'name' => $data['name'],
            'phone'    => $data['phone']
        ];

        // 5. 调用封装好的 Service，发送广播事件
        // 使用 Topic 模式：交换机名为 user.events，路由键为 user.register.success
        RabbitMQService::publishTopic('user.events','user.register.success',$eventData);


        // 6. 极速返回前端
        Log::info('注册成功');
        return json(['code' => 200, 'msg' => '注册成功']);


    }

    public function hello($name = 'ThinkPHP6')
    {
        return 'hello,' . $name;
    }
}
