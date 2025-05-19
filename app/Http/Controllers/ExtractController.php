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
        // 1. 取得原始文字
        $text = $request->input('text', '');

        // 2. 去除所有中文
        $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);

        // 3. 正則：支援各種前綴及無前綴但有 =_grp 或 =_mda 的碼，並允許連字號（-）
        $pattern = '/
        (?:                                    # 第一大群：有前綴
            @?filepan_bot:                     #   @filepan_bot: 或 filepan_bot:
          | link:\s*                           #   link:
          | (?:vi_|pk_|p_|d_|showfilesbot_|    #   vi_、pk_、p_、d_、showfilesbot_
               [vVpPdD]_|
               [vVpPdD]_datapanbot_)
        )
        [A-Za-z0-9_+\-]+                       # 主體：英數、底線、+、-
        (?:=_grp|=_mda)?                       # 可選後綴
      |
        \b                                     # 第二大群：無前綴
        [A-Za-z0-9_+\-]+                       # 主體：英數、底線、+、-
        (?:=_grp|=_mda)                        # 必須有 =_grp 或 =_mda
        \b
    /xu';

        // 4. 擷取所有符合的碼，並去重
        preg_match_all($pattern, $cleanText, $matches);
        $allCodes = array_unique($matches[0] ?? []);

        if (empty($allCodes)) {
            // 沒有任何碼，直接回傳空陣列
            return view('extract', ['codes' => []]);
        }

        // 5. 先查出已存在資料庫中的那些碼
        $existing = ExtractedCode::whereIn('code', $allCodes)
            ->pluck('code')
            ->all();

        // 6. 計算出「真正的新碼」
        $newCodes = array_values(array_diff($allCodes, $existing));

        if (!empty($newCodes)) {
            // 7. 批次 insertOrIgnore：避免重複鍵錯誤
            $now = now();
            $insertData = array_map(function($code) use ($now) {
                return [
                    'code'       => $code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $newCodes);

            // insertOrIgnore 若遇到已存在的唯一鍵，會自動跳過不丟例外
            ExtractedCode::insertOrIgnore($insertData);
        }

        // 8. 回傳給 Blade 的只包含「新碼」
        return view('extract', [
            'codes' => $newCodes,
        ]);
    }
}
