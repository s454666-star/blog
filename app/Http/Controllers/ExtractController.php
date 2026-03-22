<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExtractedCode;
use App\Services\TelegramCodeTokenService;

class ExtractController extends Controller
{
    public function __construct(
        private TelegramCodeTokenService $tokenService
    ) {
    }

    /**
     * 顯示輸入頁面
     */
    public function index()
    {
        return view('extract');
    }

    /**
     * 處理掃描、擷取、存入 DB 並回傳結果
     */
    public function process(Request $request)
    {
        $text = $request->input('text', '');
        $allCodes = $this->tokenService->extractTokens($text);

        if (empty($allCodes)) {
            return view('extract', ['codes' => []]);
        }

        $existing = ExtractedCode::whereIn('code', $allCodes)
            ->pluck('code')
            ->all();

        $newCodes = array_values(array_diff($allCodes, $existing));

        if (!empty($newCodes)) {
            $now = now();
            $insertData = array_map(function($code) use ($now) {
                return [
                    'code'       => $code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $newCodes);

            ExtractedCode::insertOrIgnore($insertData);
        }

        return view('extract', [
            'codes' => $newCodes,
        ]);
    }
}
