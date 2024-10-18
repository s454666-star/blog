<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RemoveContentRangeHeader
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        Log::info('Removing Content-Range header from response.');
        $response = $next($request);
        $response->headers->remove('Content-Range');
        return $response;
    }
}
