<?php

    use App\Http\Controllers\ActorController;
    use App\Http\Controllers\AlbumController;
    use App\Http\Controllers\AlbumPhotoController;
    use App\Http\Controllers\CodeDedupBotController;
    use App\Http\Controllers\CreditCardController;
    use App\Http\Controllers\DeliveryAddressController;
    use App\Http\Controllers\FileScreenshotController;
    use App\Http\Controllers\MemberController;
    use App\Http\Controllers\OrderController;
    use App\Http\Controllers\ProductCategoryController;
    use App\Http\Controllers\ProductController;
    use App\Http\Controllers\RandomM3u8Controller;
    use App\Http\Controllers\ReturnOrderController;
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
    Route::post('/admin-login', [UserController::class, 'login']);
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/photos', 'App\Http\Controllers\PhotoController@index');
    Route::get('/videos', 'App\Http\Controllers\MediaController@index');
    Route::get('/videos-random', 'App\Http\Controllers\VideosRandomController@index');

    Route::get('/random-video', 'App\Http\Controllers\RandomVideoController@index');
    Route::get('/random-m3u8', [RandomM3u8Controller::class, 'index']);
    //Route::get('/m3u8/all.m3u8', [RandomM3u8Controller::class, 'playlist']);

// 受保護的路由，需要通過認證
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::apiResource('members', MemberController::class);
    });
    Route::apiResource('products', ProductController::class);
    Route::get('/screenshots', [FileScreenshotController::class, 'index']);                                        // 列出所有的檔案資料
    Route::put('/screenshots/{id}/rating', [FileScreenshotController::class, 'updateRating']);                     // 更新評分
    Route::put('/screenshots/{id}/notes', [FileScreenshotController::class, 'updateNotes']);                       // 更新備註
    Route::delete('/screenshots/{id}', [FileScreenshotController::class, 'deleteFile']);                           // 刪除檔案和對應資料
    Route::delete('/screenshots/{id}/delete-screenshots', [FileScreenshotController::class, 'deleteScreenshots']); // 刪除某些截圖

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);           // 列出所有類別
    Route::get('/product-categories/{id}', [ProductCategoryController::class, 'show']);       // 根據ID獲取類別
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);          // 創建新類別
    Route::put('/product-categories/{id}', [ProductCategoryController::class, 'update']);     // 更新類別
    Route::delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy']); // 刪除類別


    Route::get('/actors', [ActorController::class, 'index']);
    Route::get('/albums', [AlbumController::class, 'index']);
    Route::get('/album-photos', [AlbumPhotoController::class, 'index']);
    Route::get('/albums/{id}', [AlbumController::class, 'show']);
    Route::put('/albums/updateDeleted', [AlbumController::class, 'updateDeleted']);
    Route::put('/albums/{id}/updateIsViewed', [AlbumController::class, 'updateIsViewed']);

    Route::get('/file-screenshots', [FileScreenshotController::class, 'index']);
    Route::get('/file-screenshots/{id}', [FileScreenshotController::class, 'show']);
    Route::post('/file-screenshots', [FileScreenshotController::class, 'store']);
    Route::put('/file-screenshots/{id}', [FileScreenshotController::class, 'update']);
    Route::delete('/file-screenshots/{id}', [FileScreenshotController::class, 'destroy']);
    Route::put('/file-screenshots/{id}/cover-image', [FileScreenshotController::class, 'updateCoverImage']);
    Route::put('/file-screenshots/{id}/is-view', [FileScreenshotController::class, 'updateIsView']);
    Route::put('/file-screenshots/{id}/rating', [FileScreenshotController::class, 'updateRating']);


    Route::post('/register', [MemberController::class, 'register']);
    Route::get('/verify-email/{token}', [MemberController::class, 'verifyEmail']);
    Route::post('/check-member-exists', [MemberController::class, 'checkMemberExists']);
    Route::post('/check-email-verified', [MemberController::class, 'checkEmailVerified']);

    Route::post('/login', [MemberController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [MemberController::class, 'logout']);
    Route::middleware('auth:sanctum')->get('/me', [MemberController::class, 'me']);

    Route::get('/verify-success', [MemberController::class, 'showVerificationSuccess']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('orders', OrderController::class);
        Route::apiResource('credit-cards', CreditCardController::class);
        Route::apiResource('delivery-addresses', DeliveryAddressController::class);
        Route::apiResource('return-orders', ReturnOrderController::class);
        Route::post('/orders/process', [OrderController::class, 'processOrder']);
        Route::put('/orders/{orderId}/items/{itemId}', [OrderController::class, 'updateItemQuantity']);
        Route::delete('/orders/{orderId}/items/{itemId}', [OrderController::class, 'deleteItem']);
    });
    Route::post('/telegram/webhook', [CodeDedupBotController::class, 'handle']);

