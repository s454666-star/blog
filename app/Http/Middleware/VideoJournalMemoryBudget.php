<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VideoJournalMemoryBudget
{
    public function handle(Request $request, Closure $next)
    {
        // Run before JSON input normalization: inline images need room for both
        // the encoded request and the DOM/JSON response. Other pages are untouched.
        if ($request->is('video-journal', 'video-journal/*')
            && in_array($request->getHost(), ['blog', 'localhost', '127.0.0.1', '::1'], true)
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            $limit = ini_parse_quantity(ini_get('memory_limit'));
            if ($limit > 0 && $limit < 768 * 1024 * 1024) {
                ini_set('memory_limit', '768M');
            }
        }

        return $next($request);
    }
}
