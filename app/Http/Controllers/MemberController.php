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
        // 會員註冊、登入等功能
        public function __construct()
        {
            $this->middleware('auth:sanctum')->except(['register', 'login', 'verifyEmail', 'checkMemberExists', 'checkEmailVerified']);
        }

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

            // 檢查是否已經驗證
            if ($member->email_verified) {
                return redirect()->route('verify.already')->with('info', '此帳號已經驗證過了。');
            }

            // 完成驗證過程
            $member->email_verified = true;
            $member->save();

            return redirect()->route('verify.success')->with('success', '電子郵件驗證成功！');
        }

        public function showAlreadyVerified()
        {
            return view('already_verified');
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

        /**
         * 獲取會員列表
         * - 管理員：獲取所有會員
         * - 非管理員：只能獲取自己的會員資訊
         */
        public function index(Request $request)
        {
            $user = $request->user(); // 獲取當前授權的使用者

            // 解析範圍
            $range = $request->input('range', [0, 49]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];
            $to   = $range[1];

            // 解析排序
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }
            $sortField     = $sort[0];
            $sortDirection = strtolower($sort[1] ?? 'asc');

            // 判斷用戶角色
            if ($user->role === 'admin') {
                // 管理員可以查看所有會員
                $query = Member::with(['deliveryAddresses']);
            } else {
                // 非管理員只能查看自己的會員資訊
                $query = Member::where('id', $user->id)
                    ->with(['deliveryAddresses']);
            }

            // 處理過濾
            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where(function($subQuery) use ($q) {
                        $subQuery->where('username', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
                }
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                // 可以在此處添加更多的過濾條件
            }

            $total = $query->count();

            $members = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            return response()->json($members, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        /**
         * 顯示指定會員資訊
         * - 管理員：可以查看任何會員
         * - 非管理員：只能查看自己的會員資訊
         */
        public function show(Request $request, $id)
        {
            $user = $request->user();

            $member = Member::with(['deliveryAddresses'])->find($id);

            if (!$member) {
                return response()->json(['message' => 'Member not found'], 404);
            }

            // 如果用戶不是管理員，且會員ID不匹配，則拒絕訪問
            if ($user->role !== 'admin' && $member->id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json($member, 200);
        }

        /**
         * 創建會員
         * - 只有管理員可以創建會員
         */
        public function store(Request $request)
        {
            $user = $request->user();

            // 檢查是否為管理員
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }

            $data = $request->validate([
                'username' => 'required|unique:members',
                'password' => 'required|min:6',
                'name'     => 'required',
                'email'    => 'required|email|unique:members',
                'phone'    => 'nullable|string',
                'address'  => 'nullable|string',
                'status'   => 'in:active,inactive',
            ]);

            $data['password'] = Hash::make($data['password']);
            $data['email_verification_token'] = Str::random(60);
            $data['email_verified'] = false;

            $member = Member::create($data);

            // 可選：寄送驗證郵件
            Mail::send('emails.verify', ['member' => $member], function($message) use ($member) {
                $message->to($member->email);
                $message->subject('請驗證您的電子郵件地址');
            });

            return response()->json($member, 201);
        }

        /**
         * 更新會員資訊
         * - 管理員可以更新任何會員
         * - 非管理員只能更新自己的會員資訊
         */
        public function update(Request $request, $id)
        {
            $user = $request->user();

            $member = Member::find($id);

            if (!$member) {
                return response()->json(['message' => 'Member not found'], 404);
            }

            // 如果用戶不是管理員，且會員ID不匹配，則拒絕訪問
            if ($user->role !== 'admin' && $member->id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $data = $request->validate([
                'username' => 'sometimes|required|unique:members,username,' . $member->id,
                'password' => 'sometimes|required|min:6',
                'name'     => 'sometimes|required',
                'email'    => 'sometimes|required|email|unique:members,email,' . $member->id,
                'phone'    => 'nullable|string',
                'address'  => 'nullable|string',
                'status'   => 'in:active,inactive',
            ]);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $member->update($data);

            return response()->json($member, 200);
        }

        /**
         * 刪除會員
         * - 只有管理員可以刪除會員
         */
        public function destroy(Request $request, $id)
        {
            $user = $request->user();

            // 檢查是否為管理員
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }

            $member = Member::find($id);

            if (!$member) {
                return response()->json(['message' => 'Member not found'], 404);
            }

            $member->delete();

            return response()->json(['message' => 'Member deleted successfully'], 200);
        }
    }
