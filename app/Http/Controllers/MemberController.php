<?php

    namespace App\Http\Controllers;

    use App\Models\Member;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Str;

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
//            $member->email_verification_token = null;
            $member->save();

            // 使用命名路由進行重導
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

    }
