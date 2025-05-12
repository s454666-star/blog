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

        // 去除所有中文（含繁簡）、其他非英數底線等符號
        $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);

        // 定義前綴 & 後綴正規
        $prefixPattern = '/\b(?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_datapanbot_|[vVpPdD]_)[A-Za-z0-9]+\b/u';
        $suffixPattern = '/\b[A-Za-z0-9]+(?:=_grp|=_mda)\b/u';

        preg_match_all($prefixPattern, $cleanText, $preMatches);
        preg_match_all($suffixPattern, $cleanText, $sufMatches);

        // 合併、去重
        $matches = array_unique(array_merge($preMatches[0], $sufMatches[0]));

        if (!empty($matches)) {
            // 已在 DB 的 code
            $existing = ExtractedCode::whereIn('code', $matches)
                ->pluck('code')
                ->all();

            // 需新插入的
            $toInsert = array_diff($matches, $existing);
            foreach ($toInsert as $code) {
                ExtractedCode::create(['code' => $code]);
            }
        }

        return view('extract', [
            'codes' => $matches,
        ]);
    }
}
