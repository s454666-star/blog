<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalOnly
{
    // Commands, deletions and outbound-URL proxies may only run from the local site (https://blog).
    public function handle(Request $request, Closure $next)
    {
        abort_unless(in_array($request->getHost(), ['blog', 'localhost', '127.0.0.1', '::1'], true)
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);

        return $next($request);
    }
}
