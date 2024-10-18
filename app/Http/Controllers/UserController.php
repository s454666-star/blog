<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // 查詢所有使用者
    public function index()
    {
        return response()->json(User::all(), 200);
    }

    // 查詢單一使用者
    public function show($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json($user, 200);
    }

    // 新增使用者
    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|unique:users,username',
            'password' => 'required|string|min:6',
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female,other',
            'birthdate' => 'nullable|date',
            'nationality' => 'nullable|string',
            'role' => 'nullable|in:admin,user,guest',
        ]);

        $validated['password'] = Hash::make($request->password);

        $user = User::create($validated);

        return response()->json($user, 201);
    }

    // 更新使用者資料
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'username' => 'nullable|string|unique:users,username,' . $id,
            'password' => 'nullable|string|min:6',
            'name' => 'nullable|string',
            'email' => 'nullable|email|unique:users,email,' . $id,
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female,other',
            'birthdate' => 'nullable|date',
            'nationality' => 'nullable|string',
            'role' => 'nullable|in:admin,user,guest',
        ]);

        if ($request->password) {
            $validated['password'] = Hash::make($request->password);
        }

        $user->update($validated);

        return response()->json($user, 200);
    }

    // 刪除使用者
    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted'], 200);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // 根據 username 找出使用者
        $user = User::where('username', $validated['username'])->first();

        // 如果找不到使用者
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // 檢查帳號是否啟用
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is not active'], 403);
        }

        // 檢查密碼是否正確
        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // 登入成功，返回使用者資料或 JWT Token 等
        // 假設此處只是返回使用者資料，您可以自行加入 JWT 或 Laravel Passport 來實現 token 驗證
        return response()->json([
            'message' => 'Login successful',
            'user' => $user
        ], 200);
    }
}
