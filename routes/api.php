<?php

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
Route::apiResource('users', UserController::class);
Route::post('/login', [UserController::class, 'login']);