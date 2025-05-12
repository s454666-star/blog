<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StaticProxyController extends Controller
{
    public function proxy($path)
    {
        // 為路徑中每段進行 urlencode（避免空格或特殊字元造成來源主機 400）
        $segments = explode('/', $path);
        $encodedSegments = array_map('rawurlencode', $segments);
        $encodedPath = implode('/', $encodedSegments);

        $remoteUrl = 'https://10.147.18.147/video/' . $encodedPath;

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
                return response("無法開啟遠端檔案", 500);
            }

            // 從來源 HTTP 回應標頭中提取需要的 header
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') !== false) {
                    header($header);
                }
                if (stripos($header, 'Content-Length:') !== false) {
                    header($header);
                }
                if (stripos($header, 'Accept-Ranges:') !== false) {
                    header($header);
                }
            }

            // 影片類型建議加上此標頭
            header('Content-Disposition: inline');

            // 傳送檔案資料串流
            fpassthru($handle);
            fclose($handle);
            exit;
        } catch (\Exception $e) {
            return response('串流錯誤', 500);
        }
    }
}
