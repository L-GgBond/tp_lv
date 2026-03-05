<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class MockQueueWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mock-queue-worker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $this->info('监听消息队列 post-views-increment...');
        while (true) {
            try {
                /**
                 * 【架构级优化：改用阻塞式拉取 blpop】
                 * @param array|string $keys    要监听的列表名称
                 * @param int          $timeout 阻塞超时时间（秒）。
                 * 设置为 5 秒意味着：如果队列为空，当前 PHP 进程会挂起并在 Redis 端阻塞等待最多 5 秒。
                 * 期间一旦有新消息进入，立刻被唤醒并拉取。这能将无意义的 CPU 轮询消耗降到接近 0。
                 */
                $message = Redis::blpop('post-views-increment', 5);
                // 如果 5 秒内没有消息，blpop 返回 null 或空数组，直接进入下一次循环
                if (empty($message)) {
                    continue;
                }

                // blpop 的返回值是一个数组：[0 => '队列名', 1 => '弹出的元素值']
                $postId = $message[1];

                /**
                 * 【修复 Bug：使用正确的 Eloquent 静态调用方式】
                 * 使用 Post::where() 触发 Laravel 底层 __callStatic 魔术方法，获取 Builder 实例
                 * * 【性能考虑】：increment 是一条直达 DB 的原子性 UPDATE 语句，不存在并发覆写问题。
                 */
                $updated = Post::where('id', $postId)->increment('views');

                if ($updated) {
                    // 同步更新 Redis 中的热度排行榜
                    Redis::zincrby('popular_posts', 1, $postId);
                    $this->info(sprintf("[%s] 成功消费: 更新文章 #%d 的浏览数", date('Y-m-d H:i:s'), $postId));
                } else {
                    $this->warn(sprintf("[%s] 消费异常: 文章 #%d 不存在或更新失败", date('Y-m-d H:i:s'), $postId));
                }
            }catch (\Exception $e){
                // 【常驻进程必须捕获异常】
                // 防止因为单次数据库抖动或 Redis 网络闪断，导致整个消费进程崩溃退出
                Log::error("Queue Worker 消费时发生严重异常: " . $e->getMessage());
                $this->error("发生异常: " . $e->getMessage());

                // 异常发生时，强制休眠 1 秒，防止由于数据库宕机导致死循环疯狂报错打满日志磁盘
                sleep(1);
            }
        }
    }
}
