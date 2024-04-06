<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use danog\MadelineProto\API;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramController extends Controller
{
    private $madelineProto;

    /**
     * @throws Throwable
     */
    public function __construct()
    {
        try {
            $settings = [
                'app_info' => [
                    'api_id'   => '24069389', // 從 https://my.telegram.org/auth 獲得
                    'api_hash' => 'fafd041ab4e7723113460be13f9e5bee', // 從 https://my.telegram.org/auth 獲得
                ]
            ];
            Log::info('準備初始化 MadelineProto', $settings);
            $this->madelineProto = new API('session.madeline', $settings);
            Log::info('MadelineProto 實例創建成功', (array)$this->madelineProto);

            Log::info('開始執行 MadelineProto->start()');
            $this->madelineProto->start();
            Log::info('MadelineProto 初始化完成');
        }
        catch (Throwable $e) {
            Log::error('MadelineProto 初始化失敗: ' . $e->getMessage(), [ $e ]);
            throw $e; // 重新拋出異常以便外部處理，或在此處理異常
        }
    }


    public function authenticate(Request $request)
    {
        $areaCode  = '+886 ';                                              // 國碼，例如 +886
        $phone     = preg_replace('/\D+/', '', $request->input('phone'));  // 移除電話號碼中的非數字字符
        $fullPhone = $areaCode . $phone;                                   // 結合國碼和電話號碼
        Log::info('嘗試登入', [ 'phone' => $fullPhone ]);

        try {
            $code     = $request->input('code');
            $password = $request->input('password'); // 接收二階段認證密碼

            if ($code) {
                Log::info('使用驗證碼進行登入', [ 'code' => $code ]);
                $authorization = $this->madelineProto->complete_phone_login($code);
                Log::info('完成電話登入', [ 'authorization' => $authorization ]);

                if (isset($authorization['_']) && $authorization['_'] === 'account.password') {
                    Log::info('需要二階段認證密碼');
                    if ($password) {
                        $authorization = $this->madelineProto->complete_2fa_login($password);
                        Log::info('完成二階段認證登入', [ 'authorization' => $authorization ]);
                        $loginSuccess = true;
                    } else {
                        Log::warning('未提供二階段認證密碼');
                        return back()->with('message', '請輸入二階段認證密碼');
                    }
                } else {
                    $loginSuccess = true;
                }
            } else {
                Log::info('發送驗證碼到', [ 'phone' => $fullPhone ]);
                $this->madelineProto->phone_login($fullPhone);
                return back()->with('message', '請檢查您的電話，並輸入收到的驗證碼');
            }
        }
        catch (\Throwable $e) {
            Log::error('登入過程中出現異常', [ 'error' => $e->getMessage(), 'exception' => $e ]);
            return back()->with('error', '登入失敗：' . $e->getMessage());
        }

        if (isset($loginSuccess) && $loginSuccess) {
            Log::info('登入成功', [ 'phone' => $fullPhone ]);
            $user = User::where('phone', $fullPhone)->firstOrCreate([
                'phone'    => $fullPhone,
                'name'     => 'Telegram User',
                'email'    => $fullPhone . '@telegram.com',
                'password' => bcrypt(Str::random(10)),
            ]);
            Auth::login($user);
            return redirect('/dashboard')->with('message', '登入成功！');
        } else {
            Log::warning('登入失敗', [ 'phone' => $fullPhone ]);
            return back()->with('error', '登入失敗');
        }
    }

    public function getChatList(): JsonResponse
    {
        Log::info('獲取聊天列表');
        try {
            $loggedIn = $this->madelineProto->get_self();
            Log::info('當前登入用戶', [ 'user' => $loggedIn ]);

            if (!$loggedIn) {
                Log::warning('沒有用戶通過MadelineProto登錄');
                return response()->json([]);
            }

            $chatList = $this->madelineProto->getDialogs();
            Log::info('獲取到的聊天列表', [ 'chatList' => $chatList ]);
            return response()->json($chatList);
        }
        catch (\Throwable $e) {
            Log::error('獲取聊天列表時出錯', [ 'error' => $e->getMessage(), 'exception' => $e ]);
            return response()->json([], 500);
        }
    }
}
