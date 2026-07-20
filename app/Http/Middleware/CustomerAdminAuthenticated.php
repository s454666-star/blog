<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('customer-admin.username');
        $signedIn = (string) $request->session()->get('customer_admin_user');

        if ($configured === '' || $signedIn === '' || ! hash_equals($configured, $signedIn)) {
            return redirect()->route('customer-admin.login');
        }

        return $next($request);
    }
}
