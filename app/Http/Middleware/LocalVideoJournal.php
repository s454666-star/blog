<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalVideoJournal
{
    public function handle(Request $request, Closure $next)
    {
        // This tool serves user-supplied local files. Never expose it on the public site.
        abort_unless(in_array($request->getHost(), ['blog', 'localhost', '127.0.0.1', '::1'], true)
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
