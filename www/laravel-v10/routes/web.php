<?php

use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

use App\Http\Controllers\RabbitMQController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/rabbitmq',[RabbitMQController::class,'index']);
Route::get('/rabbitmq/test1',[RabbitMQController::class,'test1']);
Route::get('/rabbitmq/test2',[RabbitMQController::class,'test2']);


Route::get('/redis', function () {
    dd(\Illuminate\Support\Facades\Redis::connection());
});
Route::get('/redis/site_visits', function () {
    return '网站全局访问量：' . \Illuminate\Support\Facades\Redis::get('site_total_visits');
});


Route::get('/posts/popular', [PostController::class, 'popular']);
Route::get('/posts/{id}', [PostController::class, 'show'])->where('id', '[0-9]+');
