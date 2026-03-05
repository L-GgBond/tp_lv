<?php

namespace App\Http\Repos;

use App\Models\Post;
use Illuminate\Support\Facades\Redis;

class PostRepo
{
    protected Post $post;

    public function __construct(Post $post)
    {
        $this->post = $post;
    }

    public function getById(int $id, array $columns = ['*'])
    {
        $cacheKey = 'post_'.$id;
        if(Redis::exists($cacheKey)){
            return unserialize(Redis::get($cacheKey));
        }

        $post = $this->post->select($columns)->find($id);
        if (!$post) {
            return null;
        }

        Redis::setex($cacheKey,1 * 60 * 60, serialize($post));// 缓存 1 小时
        return $post;
    }

    public function getByManyId(array $ids, array $columns = ['*'], callable $callback = null)
    {
        $query = $this->post->select($columns)->whereIn('id', $ids);
        if($query){
            $query = $callback($query);
        }

        return $query->get();
    }

    public function addViews(Post $post)
    {
//        $post->increment('views');
//        if ($post->save()) {
//            // 将当前文章浏览数 +1，存储到对应 Sorted Set 的 score 字段
//            Redis::zincrby('popular_posts', 1, $post->id);
//        }
//        return $post->views;

        Redis::rpush('post-views-increment', $post->id);
        return ++$post->views;
    }

    // 热门文章排行榜
    public function trending($num = 10)
    {
        $cacheKey = 'trendingPostsKey'. '_' . $num;
        if (Redis::exists($cacheKey)) {
            return unserialize(Redis::get($cacheKey));
        }

        $postIds = Redis::zrevrange('popular_posts', 0, $num - 1);
        if (!$postIds) {
            return null;
        }

        $idsStr = implode(',', $postIds);

        $posts = $this->getByManyId($postIds, ['*'], function ($query) use ($idsStr) {
            return $query->orderByRaw('field(`id`, ' . $idsStr . ')');
        });

        Redis::setex($cacheKey, 10 * 60, serialize($posts));  // 缓存 10 分钟
        return $posts;
    }

}
