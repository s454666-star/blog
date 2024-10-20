<?php

use App\Http\Controllers\FileScreenshotController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/photos', 'App\Http\Controllers\PhotoController@index');
Route::get('/videos', 'App\Http\Controllers\MediaController@index');
Route::get('/videos-random', 'App\Http\Controllers\VideosRandomController@index');
// 登入路由，無需驗證
Route::post('/login', [UserController::class, 'login']);

// 受保護的路由，需要通過認證
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('users', UserController::class);
    // 可以在這裡添加更多受保護的路由
});

Route::get('/screenshots', [FileScreenshotController::class, 'index']);  // 列出所有的檔案資料
Route::put('/screenshots/{id}/rating', [FileScreenshotController::class, 'updateRating']);  // 更新評分
Route::put('/screenshots/{id}/notes', [FileScreenshotController::class, 'updateNotes']);    // 更新備註
Route::delete('/screenshots/{id}', [FileScreenshotController::class, 'deleteFile']);        // 刪除檔案和對應資料
Route::delete('/screenshots/{id}/delete-screenshots', [FileScreenshotController::class, 'deleteScreenshots']); // 刪除某些截圖