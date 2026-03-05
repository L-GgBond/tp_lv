<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class MockViewPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mock-view-posts';

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
        // 1、先清空 posts 表
        Post::truncate();
        // 2、删除对应的 Redis 键
        Redis::del('popular_posts');

        // 3、生成 100 篇测试文章
        Post::factory()->count(100)->create();

        // 4、模拟对所有文章进行 10000 次随机访问
        for ($i = 0; $i < 10000; $i++) {
            $postId = mt_rand(1, 100);
            $response = Http::timeout(3)->get('http://host.docker.internal:8088/posts/' . $postId);
            $this->info($response->body());
        }
    }
}
