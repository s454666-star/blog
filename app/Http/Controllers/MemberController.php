<?php

    namespace App\Http\Controllers;

    use App\Models\Member;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Str;
    use Illuminate\Validation\ValidationException;

    class MemberController extends Controller
    {
        // 會員註冊
        public function register(Request $request)
        {
            \Log::info('Starting registration process.');

            $validatedData = $request->validate([
                                                    'username' => 'required|unique:members',
                                                    'password' => 'required|min:6',
                                                    'name'     => 'required',
                                                    'email'    => 'required|email|unique:members',
                                                ]);

            \Log::info('Validation passed for registration data.');

            try {
                $member = Member::create([
                                             'username'                 => $request->username,
                                             'password'                 => Hash::make($request->password),
                                             'name'                     => $request->name,
                                             'phone'                    => $request->phone,
                                             'email'                    => $request->email,
                                             'address'                  => $request->address,
                                             'email_verification_token' => Str::random(60),
                                             'email_verified'           => false,
                                             'status'                   => 'active',
                                         ]);

                \Log::info('Member created successfully with ID: ' . $member->id);

                // 寄送驗證郵件
                Mail::send('emails.verify', ['member' => $member], function($message) use ($member) {
                    $message->to($member->email);
                    $message->subject('請驗證您的電子郵件地址');
                });

                \Log::info('Verification email sent to: ' . $member->email);

            } catch (\Exception $e) {
                \Log::error('Error during registration or email sending: ' . $e->getMessage());
                return response()->json(['message' => '註冊失敗，請稍後再試。'], 500);
            }

            return response()->json(['message' => '註冊成功，請檢查您的電子郵件以完成驗證。']);
        }

        // 顯示驗證成功頁面
        public function showVerificationSuccess()
        {
            return view('verify_success');
        }

        // 電子郵件驗證
        public function verifyEmail($token)
        {
            $member = Member::where('email_verification_token', $token)->first();

            if (!$member) {
                return redirect('/')->with('error', '無效的驗證連結。');
            }

            $member->email_verified = 1;
            $member->email_verification_token = null;
            $member->save();

            return redirect()->route('verify.success')->with('success', '電子郵件驗證成功！');
        }

        // 檢查會員是否存在
        public function checkMemberExists(Request $request)
        {
            $exists = Member::where('email', $request->email)->exists();
            return response()->json(['exists' => $exists]);
        }

        // 檢查會員是否已驗證
        public function checkEmailVerified(Request $request)
        {
            $member = Member::where('email', $request->email)->first();

            if ($member && $member->email_verified) {
                return response()->json(['verified' => true]);
            }

            return response()->json(['verified' => false]);
        }

        // 會員登入
        public function login(Request $request)
        {
            $request->validate([
                                   'email'    => 'required|email',
                                   'password' => 'required',
                               ]);

            $member = Member::where('email', $request->email)->first();

            if (!$member || !Hash::check($request->password, $member->password)) {
                throw ValidationException::withMessages([
                                                            'email' => ['提供的認證資料不正確。'],
                                                        ]);
            }

            // 檢查是否已驗證郵件
            if (!$member->email_verified) {
                return response()->json(['message' => '請先驗證您的電子郵件。'], 403);
            }

            // 創建 token
            $token = $member->createToken('auth_token')->plainTextToken;

            return response()->json([
                                        'access_token' => $token,
                                        'token_type'   => 'Bearer',
                                        'user'         => [
                                            'username'       => $member->username,
                                            'email'          => $member->email,
                                            'email_verified' => $member->email_verified,
                                        ],
                                    ]);
        }

        // 會員登出
        public function logout(Request $request)
        {
            // 撤銷當前使用的 token
            $request->user()->currentAccessToken()->delete();

            return response()->json(['message' => '成功登出']);
        }

        // 獲取會員資訊
        public function me(Request $request)
        {
            return response()->json([
                                        'username'       => $request->user()->username,
                                        'email'          => $request->user()->email,
                                        'email_verified' => $request->user()->email_verified,
                                    ]);
        }
    }
