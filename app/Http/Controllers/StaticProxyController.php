<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StaticProxyController extends Controller
{
    public function proxy($path)
    {
        $remoteUrl = 'https://10.147.18.147/video/' . $path;

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => "GET",
                    'header' => "User-Agent: Laravel\r\n",
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

            // 取得 Content-Type
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') !== false) {
                    header($header); // 直接傳回原始 Content-Type
                }
                if (stripos($header, 'Content-Length:') !== false) {
                    header($header);
                }
                if (stripos($header, 'Accept-Ranges:') !== false) {
                    header($header);
                }
            }

            // 若是 video/mp4，建議加上這個
            header('Content-Disposition: inline');

            // 傳送影片串流資料
            fpassthru($handle);
            fclose($handle);
            exit;
        } catch (\Exception $e) {
            return response('Error streaming file', 500);
        }
    }
}
