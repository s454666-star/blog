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

        // 2. 一支 pattern：同時匹配 @filepan_bot:xxx、filepan_bot:xxx 及其他各種前綴＋可選後綴
        $pattern = '/@?filepan_bot:[A-Za-z0-9_]+|\b(?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_datapanbot_|[vVpPdD]_)[A-Za-z0-9_]+(?:=_grp|=_mda)?\b/u';

        // 3. 擷取所有符合的碼
        preg_match_all($pattern, $cleanText, $m);
        $allCodes = array_unique($m[0] ?? []);

        // 4. 過濾出資料庫裡還沒有的
        $existing = ExtractedCode::whereIn('code', $allCodes)
            ->pluck('code')
            ->all();
        $newCodes = array_values(array_diff($allCodes, $existing));

        // 5. 將新碼存進 DB
        foreach ($newCodes as $code) {
            ExtractedCode::create(['code' => $code]);
        }

        // 6. 回傳給前端的，只有「新碼」
        return view('extract', [
            'codes' => $newCodes,
        ]);
    }
}
