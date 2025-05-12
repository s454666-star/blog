<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExtractedCode;

class ExtractController extends Controller
{
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

        // 1. 去除中文
        $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);

        // 2. 統一用一個 pattern，同時接受 _、數字與可選後綴
        $pattern = '/\b(?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_datapanbot_|[vVpPdD]_)[A-Za-z0-9_]+(?:=_grp|=_mda)?\b/u';

        // 3. 擷取所有符合的碼
        preg_match_all($pattern, $cleanText, $m);
        $codes = array_unique($m[0] ?? []);

        // 4. 如果有新的，才存 DB
        if (!empty($codes)) {
            $existing = ExtractedCode::whereIn('code', $codes)
                ->pluck('code')
                ->all();

            $toInsert = array_diff($codes, $existing);
            foreach ($toInsert as $code) {
                ExtractedCode::create(['code' => $code]);
            }
        }

        // 5. 一定要帶回 codes（哪怕是空陣列也要帶）
        return view('extract', [
            'codes' => $codes,
        ]);
    }
}
