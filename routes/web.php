<?php

use App\Http\Controllers\BlogBtController;
use App\Http\Controllers\BlogController;
    use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\ExtractController;
use App\Http\Controllers\FetchController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ImageFullController;
use App\Http\Controllers\LibraryController;
    use App\Http\Controllers\MemberController;
    use App\Http\Controllers\OCRController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PdfController2;
use App\Http\Controllers\StaticProxyController;
use App\Http\Controllers\TelegramController;
    use App\Http\Controllers\TestImageController;
    use App\Http\Controllers\UploadStorageController;
    use App\Http\Controllers\UserController;
    use App\Http\Controllers\VideoPlayerController;
    use App\Http\Controllers\VideosController;
    use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/
Route::get('/videos', [VideosController::class, 'index'])->name('video.index');
Route::get('/videos/load-more', [VideosController::class, 'loadMore'])->name('video.loadMore');
Route::post('/videos/upload', [VideosController::class, 'upload'])->name('video.upload');
Route::post('/videos/deleteSelected', [VideosController::class, 'deleteSelected'])->name('video.deleteSelected');
Route::post('/videos/delete-screenshot', [VideosController::class, 'deleteScreenshot'])->name('video.deleteScreenshot');
Route::post('/videos/upload-face-screenshot', [VideosController::class, 'uploadFaceScreenshot'])->name('video.uploadFaceScreenshot');
Route::post('/videos/set-master-face', [VideosController::class, 'setMasterFace'])->name('video.setMasterFace');
Route::get('/videos/load-master-faces', [VideosController::class, 'loadMasterFaces'])->name('video.loadMasterFaces');
Route::get('/videos/findPage', [VideosController::class, 'findPage'])->name('video.findPage');
Route::post('/videos', [VideosController::class, 'store'])->name('video.store');

Route::middleware('web')->post('/admin-login', [UserController::class, 'login']);

Route::get('/encrypt', [EncryptionController::class, 'index'])->name('encrypt.index');
Route::post('/encrypt', [EncryptionController::class, 'encrypt'])->name('encrypt.file');
Route::post('/decrypt', [EncryptionController::class, 'decrypt'])->name('decrypt.folder');
Route::post('/download-folder/{folder}', [EncryptionController::class, 'downloadFolder'])->name('download.folder');
Route::post('/download-file/{file}', [EncryptionController::class, 'downloadFile'])->name('download.file');
Route::delete('/delete-folder/{folder}', [EncryptionController::class, 'deleteFolder'])->name('delete.folder');
Route::delete('/delete-file/{file}', [EncryptionController::class, 'deleteFile'])->name('delete.file');
Route::post('/download-chunk/{folder}/{file}', [EncryptionController::class, 'downloadChunk'])->name('download.chunk');
Route::get('/videos/random', [VideosController::class, 'getRandomVideos'])->name('videos.random');
Route::get('/videos/test', [VideosController::class, 'getTest'])->name('videos.test');
Route::get('/video-player', [VideosController::class, 'player'])->name('videos.player');
Route::get('/api/videos/random-type', [VideosController::class, 'getRandomVideoType'])->name('videos.api.random_type');
Route::get('/videoplayer-list', [VideoPlayerController::class, 'getVideoPlayerList'])
        ->name('videos.api.videoplayer_list');
Route::get('/videos/search', [VideosController::class, 'search'])->name('videos.search');
Route::delete('/videos/{id}', [VideosController::class, 'destroy'])->name('videos.destroy');

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
Route::get('/get-chat-list', [ TelegramController::class, 'getChatList' ])->name('telegram.chat-list');
Route::post('/telegram/auth', [ TelegramController::class, 'authenticate' ])->name('telegram.auth');

// 博客相關路由
Route::prefix('blog')->group(function () {
    Route::get('/', [ BlogController::class, 'index' ])->name('blog.index');
    Route::delete('/articles/{article}', [ BlogController::class, 'destroy' ])->name('articles.destroy');
    Route::delete('/batch-delete', [ BlogController::class, 'batchDelete' ])->name('blog.batch-delete');
    Route::post('/preserve', [ BlogController::class, 'preserve' ])->name('blog.preserve');
    Route::get('/show-preserved', [ BlogController::class, 'showPreserved' ])->name('blog.show-preserved');
    Route::delete('/batch-delete-bt', [ BlogBtController::class, 'batchDelete' ])->name('blogBt.batch-delete');
});

Route::get('/blog-bt', [ BlogBtController::class, 'index' ])->name('blogBt.index');
Route::get('/bt', [ BlogBtController::class, 'index' ])->name('blogBt.index');

Route::get('/ocr', function () {
    return view('ocr');
});

Route::post('/ocr', [ OCRController::class, 'recognizeText' ]);

//Route::get('/upload-pdf', [PdfController::class, 'showUploadForm'])->name('pdf.upload');
//Route::post('/upload-pdf', [PdfController::class, 'extractText'])->name('pdf.extract-text');

//Route::get('/upload-pdf2', [PdfController2::class, 'showUploadForm'])->name('pdf2.upload');
//Route::post('/upload-pdf2', [PdfController2::class, 'extractText'])->name('pdf2.extract-text');
Route::get('/export-excel', [ PdfController::class, 'exportExcel' ])->name('export_excel');
Route::get('/get-img', [ ImageController::class, 'getImageBase64' ]);
Route::get('/get-full-img', [ ImageFullController::class, 'getImages' ]);

Route::get('/gallery', [ GalleryController::class, 'index' ])->name('gallery.index');
Route::get('/gallery/load-images', [ GalleryController::class, 'loadImages' ])->name('gallery.load-images');
// 其他自訂頁面
Route::view('/my-page', 'my')->name('my-page');
Route::view('/product', 'product')->name('product');
Route::view('/snake', 'snake')->name('snake-game');
Route::get('/upload', function () {
    return view('upload');
});

Route::post('/upload', [ UploadStorageController::class, 'upload' ]);

// routes/web.php
Route::get('/videos-management', [ App\Http\Controllers\LibraryController::class, 'index' ])->name('videos.index');
Route::post('/generate-thumbnails', [LibraryController::class, 'generateThumbnails'])->name('generate-thumbnails');

Route::get('/phpinfo', function () {
    phpinfo();
});

Route::get('/env', function () {
    dd(env('APP_KEY'), $_ENV, getenv('APP_KEY'));
});

Route::view('/product-import2', 'fetch-url');
Route::post('/fetch', [ FetchController::class, 'fetchData' ]);
Route::get('/verify-success', [MemberController::class, 'showVerificationSuccess'])->name('verify.success');
Route::get('/already-verified', [MemberController::class, 'showAlreadyVerified'])->name('verify.already');

Route::get('/data/{path}', [StaticProxyController::class, 'proxy'])
    ->where('path', '.*'); // 支援多層目錄
Route::get('/proxy-image', [TestImageController::class, 'proxy']);

Route::get('/extract', [ExtractController::class, 'index'])->name('extract.index');
Route::post('/extract', [ExtractController::class, 'process'])->name('extract.process');
