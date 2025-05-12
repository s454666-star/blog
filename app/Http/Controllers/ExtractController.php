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
            (?:                                         # 第一大群：有前綴的情況
                @?filepan_bot:                          #   @filepan_bot: 或 filepan_bot:
              | link:\s*                                #   link:
              | (?:vi_|pk_|p_|d_|showfilesbot_|         #   vi_、pk_、p_、d_、showfilesbot_
                   [vVpPdD]_|
                   [vVpPdD]_datapanbot_)
            )
            [A-Za-z0-9_+]+                              # 主體：英數底線加號
            (?:=_grp|=_mda)?                            # 可選後綴
          |
            \b                                          # 第二大群：**沒有**前綴，只看結尾
            [A-Za-z0-9_+]+                              # 任意英數底線加號
            (?:=_grp|=_mda)                             # 必須有 =_grp 或 =_mda
            \b
        /xu';

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
