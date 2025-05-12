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

        // 去除所有中文
        $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);

        // 最終合併所有 prefix 的 pattern：
        $pattern = '/
            @?filepan_bot:[A-Za-z0-9_]+(?:=_grp|=_mda)?     # @filepan_bot:xxx 或 filepan_bot:xxx
            |
            \blink:\s*[A-Za-z0-9_]+(?:=_grp|=_mda)?\b       # link: xxx
            |
            \b
            (?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_        # 既有各種前綴
            |[vVpPdD]_datapanbot_)
            [A-Za-z0-9_]+
            (?:=_grp|=_mda)?
            \b
        /xu';  // x = allow comments/whitespace, u = unicode

        // 擷取
        preg_match_all($pattern, $cleanText, $m);
        $allCodes = array_unique($m[0] ?? []);

        // 過濾資料庫已存在的
        $existing = ExtractedCode::whereIn('code', $allCodes)
            ->pluck('code')
            ->all();
        $newCodes = array_values(array_diff($allCodes, $existing));

        // 存入新碼
        foreach ($newCodes as $code) {
            ExtractedCode::create(['code' => $code]);
        }

        // 回傳給 Blade 的只包含「新碼」
        return view('extract', [
            'codes' => $newCodes,
        ]);
    }
}
