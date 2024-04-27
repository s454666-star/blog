<?php

use App\Http\Controllers\BlogBtController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ImageFullController;
use App\Http\Controllers\OCRController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PdfController2;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// 靜態頁面和身份驗證頁面
Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');

Route::get('/login', function () {
    return view('telegramLogin');
})->name('login');

// Telegram 相關路由
Route::get('/get-chat-list', [TelegramController::class, 'getChatList'])->name('telegram.chat-list');
Route::post('/telegram/auth', [TelegramController::class, 'authenticate'])->name('telegram.auth');

// 博客相關路由
Route::prefix('blog')->group(function () {
    Route::get('/', [BlogController::class, 'index'])->name('blog.index');
    Route::delete('/articles/{article}', [BlogController::class, 'destroy'])->name('articles.destroy');
    Route::delete('/batch-delete', [BlogController::class, 'batchDelete'])->name('blog.batch-delete');
    Route::post('/preserve', [BlogController::class, 'preserve'])->name('blog.preserve');
    Route::get('/show-preserved', [BlogController::class, 'showPreserved'])->name('blog.show-preserved');
    Route::delete('/batch-delete-bt', [BlogBtController::class, 'batchDelete'])->name('blogBt.batch-delete');
});

Route::get('/blog-bt', [BlogBtController::class, 'index'])->name('blogBt.index');
Route::get('/bt', [BlogBtController::class, 'index'])->name('blogBt.index');

Route::get('/ocr', function () {
    return view('ocr');
});

Route::post('/ocr', [OCRController::class, 'recognizeText']);

//Route::get('/upload-pdf', [PdfController::class, 'showUploadForm'])->name('pdf.upload');
//Route::post('/upload-pdf', [PdfController::class, 'extractText'])->name('pdf.extract-text');

//Route::get('/upload-pdf2', [PdfController2::class, 'showUploadForm'])->name('pdf2.upload');
//Route::post('/upload-pdf2', [PdfController2::class, 'extractText'])->name('pdf2.extract-text');
Route::get('/export-excel', [PdfController::class, 'exportExcel'])->name('export_excel');
Route::get('/get-img', [ImageController::class, 'getImageBase64']);
Route::get('/get-full-img', [ImageFullController::class, 'getImages']);

Route::get('/gallery', 'GalleryController@index')->name('gallery.index');
Route::get('/gallery/load-images', 'GalleryController@loadImages')->name('gallery.load-images');

// 其他自訂頁面
Route::view('/my-page', 'my')->name('my-page');
Route::view('/product', 'product')->name('product');
Route::view('/snake', 'snake')->name('snake-game');
