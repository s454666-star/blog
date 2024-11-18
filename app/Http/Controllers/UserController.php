<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // 查詢所有使用者
    public function index(Request $request)
    {
        // 解析前端傳來的 `range` 參數
        $range = $request->input('range', [0, 49]);  // 預設返回 0 到 49 筆
        if (is_string($range)) {
            $range = json_decode($range, true);
        }
        $from = $range[0];  // 起始行
        $to = $range[1];    // 結束行

        // 解析前端傳來的 `sort` 參數
        $sort = $request->input('sort', ['id', 'asc']);
        if (is_string($sort)) {
            $sort = json_decode($sort, true);
        }

        // 檢查是否解析成功並為陣列
        if (!is_array($sort) || count($sort) < 2) {
            return response()->json(['message' => 'Invalid sort parameter format.'], 400);
        }

        // 檢查並取得排序欄位與方向
        $sortField = $sort[0];  // 排序欄位 (如 "id")
        $sortDirection = isset($sort[1]) ? strtolower($sort[1]) : 'asc';  // 排序方向，轉換成小寫 ("asc" 或 "desc")

        // 檢查排序方向是否為 "asc" 或 "desc"
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            return response()->json(['message' => 'Invalid sort direction. Must be "asc" or "desc".'], 400);
        }

        // 建立查詢，並應用過濾條件（如果有的話）
        $query = User::query();

        // 解析過濾參數
        $filters = $request->input('filter', []);
        if (!empty($filters)) {
            if (isset($filters['q'])) {
                $q = $filters['q'];
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('name', 'like', "%{$q}%")
                        ->orWhere('username', 'like', "%{$q}%");
                });
            }
            // 可以根據需要添加更多的過濾條件
        }

        // 計算總筆數（應用過濾後）
        $total = $query->count();

        // 查詢數據並按照指定範圍和排序返回
        $users = $query->orderBy($sortField, $sortDirection)
            ->skip($from)
            ->take($to - $from + 1)
            ->get();

        // 返回資料並添加 X-Total-Count 標頭
        return response()->json($users, 200)
            ->header('X-Total-Count', $total)
            ->header('Content-Range', "items {$from}-{$to}/{$total}")
            ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
    }

    // 查詢單一使用者
    public function show($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json([ 'message' => 'User not found' ], 404);
        }
        return response()->json($user, 200);
    }

    // 新增使用者
    public function store(Request $request)
    {
        $validated = $request->validate([
            'username'    => 'required|string|unique:users,username',
            'password'    => 'required|string|min:6',
            'name'        => 'required|string',
            'email'       => 'required|email|unique:users,email',
            'phone'       => 'nullable|string',
            'address'     => 'nullable|string',
            'gender'      => 'nullable|in:male,female,other',
            'birthdate'   => 'nullable|date',
            'nationality' => 'nullable|string',
            'role'        => 'nullable|in:admin,user,guest',
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
            return response()->json([ 'message' => 'User not found' ], 404);
        }

        $validated = $request->validate([
            'username'    => 'nullable|string|unique:users,username,' . $id,
            'password'    => 'nullable|string|min:6',
            'name'        => 'nullable|string',
            'email'       => 'nullable|email|unique:users,email,' . $id,
            'phone'       => 'nullable|string',
            'address'     => 'nullable|string',
            'gender'      => 'nullable|in:male,female,other',
            'birthdate'   => 'nullable|date',
            'nationality' => 'nullable|string',
            'role'        => 'nullable|in:admin,user,guest',
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
            return response()->json([ 'message' => 'User not found' ], 404);
        }

        $user->delete();

        return response()->json([ 'message' => 'User deleted' ], 200);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required'],
            'password' => ['required'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // 發放 Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }
}
