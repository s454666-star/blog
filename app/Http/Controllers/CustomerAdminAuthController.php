<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class CustomerAdminAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('customer_admin_user')) {
            return redirect()->route('customer-admin.dashboard');
        }

        return view('customer-admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $key = 'customer-admin-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['username' => '登入嘗試次數過多，請稍後再試。'])->onlyInput('username');
        }

        $username = (string) config('customer-admin.username');
        $passwordHash = (string) config('customer-admin.password_hash');
        $valid = $username !== ''
            && $passwordHash !== ''
            && hash_equals($username, $credentials['username'])
            && Hash::check($credentials['password'], $passwordHash);

        if (! $valid) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['username' => '帳號或密碼不正確。'])->onlyInput('username');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('customer_admin_user', $username);

        return redirect()->intended(route('customer-admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('customer_admin_user');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer-admin.login');
    }
}
