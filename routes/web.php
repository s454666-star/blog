<?php

use App\Http\Controllers\BlogBtController;
use App\Http\Controllers\BlogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/blog-bt', [BlogBtController::class, 'index'])->name('blogBt.index');


Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::delete('/articles/{article}', [BlogController::class, 'destroy'])->name('articles.destroy');
Route::delete('/blog/batch-delete', [BlogController::class, 'batchDelete'])->name('blog.batch-delete');
Route::delete('/blog/batch-delete-bt', [BlogBtController::class, 'batchDelete'])->name('blog.batch-delete');


Route::get('/', function () {
    return view('welcome');
});


// 添加一個路由指向你的視圖
Route::get('/my-page', function () {
    return view('my');
});

// 添加一個路由指向你的視圖
Route::get('/product', function () {
    return view('product');
});

// web.php
Route::get('/snake', function () {
    return view('snake');
});
