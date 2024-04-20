<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class TextExport implements FromCollection, WithHeadings
{
    protected $text;

    public function __construct($text)
    {
        $this->text = $text;
    }

    public function collection()
    {
        // 搜索 "建物門牌:" 開始到文本結尾
        $pattern = '/建物門牌:(.*)/';
        preg_match($pattern, $this->text, $matches);

        $address = trim($matches[1] ?? '未找到');  // 如果沒有找到則返回 '未找到'

        // 返回包含提取的建物門牌資訊的集合
        return new Collection([
            ['', $address]  // 第一列為空，資料放在第二列
        ]);
    }

    public function headings(): array
    {
        return ['建物門牌', ''];
    }
}
