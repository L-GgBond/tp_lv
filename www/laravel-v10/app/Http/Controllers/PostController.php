<?php

namespace App\Http\Controllers;

use App\Http\Repos\PostRepo;

class PostController extends Controller
{

    protected PostRepo $postRepo;

    public function __construct(PostRepo $postRepo)
    {
        $this->postRepo = $postRepo;
    }

    // 浏览文章
    public function show(int $id)
    {
       $post = $this->postRepo->getById($id);
       $views = $this->postRepo->addViews($post);
        return "Show Post #{$post->id}, Views: {$views}";
    }

    // 获取热门文章排行榜
    public function popular()
    {
        $posts = $this->postRepo->trending(10);
        if ($posts) {
            dump($posts->toArray());
        }
    }
}
