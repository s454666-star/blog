<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StaticProxyController extends Controller
{
    public function proxy($path)
    {
        $segments = explode('/', $path);
        $encodedSegments = array_map('rawurlencode', $segments);
        $encodedPath = implode('/', $encodedSegments);

        $remoteUrl = 'http://10.147.18.147/video/' . $encodedPath;

        try {
            // 取得 Range 標頭
            $range = request()->header('Range');

            $headers = [
                'User-Agent: Laravel',
            ];
            if ($range) {
                $headers[] = "Range: $range";
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);

            $handle = fopen($remoteUrl, 'rb', false, $context);
            if (!$handle) {
                return response("Can't open remote file", 500);
            }

            // 回傳來源的標頭
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') !== false ||
                    stripos($header, 'Content-Length:') !== false ||
                    stripos($header, 'Accept-Ranges:') !== false ||
                    stripos($header, 'Content-Range:') !== false
                ) {
                    header($header);
                }
            }

            header('Content-Disposition: inline');

            // 若有 Content-Range，就回傳 206 Partial Content，否則 200 OK
            $httpCode = collect($http_response_header)
                ->first(fn($h) => str_starts_with($h, 'HTTP/'));
            if (strpos($httpCode, '206') !== false) {
                http_response_code(206);
            }

            fpassthru($handle);
            fclose($handle);
            exit;
        } catch (\Exception $e) {
            return response('Error streaming with range', 500);
        }
    }
}
