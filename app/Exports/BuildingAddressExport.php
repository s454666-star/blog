<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class BuildingAddressExport
{
    protected $text;

    public function __construct($text)
    {
        $this->text = $text;
    }

    public function export()
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // 假設原始文本是 Big5 編碼，需要轉換為 UTF-8
        $utf8Text = mb_convert_encoding($this->text, 'UTF-8', 'Big5');

        // 搜索 "建物門牌:" 開始到文本結尾的任何字符，包括中文和換行符
        $pattern = '/建物門牌:(.*)/us';  // 使用 u 修飾符支持 UTF-8，使用 s 使 . 匹配包括換行符在內的任何字符
        preg_match($pattern, $utf8Text, $matches);

        $address = trim($matches[1] ?? '未找到');  // 如果沒有找到則返回 '未找到'

        // 設置標題和數據
        $sheet->setCellValue('A1', '建物門牌');
        $sheet->setCellValue('B1', $address);

        // 創建 Excel 文件
        $writer   = new Xlsx($spreadsheet);
        $fileName = 'building_address.xlsx';
        $writer->save($fileName);

        return $fileName;
    }
}
