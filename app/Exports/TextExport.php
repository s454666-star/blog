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
        // 搜索 "建物門牌:" 到 "\r\n" 的文本
        $pattern = '/建物門牌:(.*?)\r\n/';
        preg_match($pattern, $this->text, $matches);

        $address = $matches[1] ?? '未找到';  // 如果沒有找到則返回 '未找到'

        // 返回包含提取的建物門牌資訊的集合
        return new Collection([
            ['content' => $address]
        ]);
    }

    public function headings(): array
    {
        return ['建物門牌'];
    }
}
