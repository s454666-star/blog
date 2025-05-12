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

        // 1. 去除所有中文
        $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);

        // 2. 一次完成前綴、底線、可選後綴的擷取
        $pattern = '/\b(?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_datapanbot_|[vVpPdD]_)[A-Za-z0-9_]+(?:=_grp|=_mda)?\b/u';
        preg_match_all($pattern, $cleanText, $m);
        $allCodes = array_unique($m[0] ?? []);

        // 3. 先找出已存在的，然後只留下新碼
        $existing = ExtractedCode::whereIn('code', $allCodes)
            ->pluck('code')
            ->all();
        $newCodes = array_values(array_diff($allCodes, $existing));

        // 4. 新碼存入 DB
        foreach ($newCodes as $code) {
            ExtractedCode::create(['code' => $code]);
        }

        // 5. 傳給 Blade 的，只是「新碼」陣列
        return view('extract', [
            'codes' => $newCodes,
        ]);
    }
}
