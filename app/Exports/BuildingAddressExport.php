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
        $utf8Text = $this->text;

        $encoding = mb_detect_encoding($utf8Text, 'UTF-8, Big5', true);
        $encodingInfo = $encoding ?: 'Unknown';

        file_put_contents('utf8_text_output.txt', $utf8Text . "\nEncoding: " . $encodingInfo, FILE_APPEND);
        // 搜索 "建物門牌:" 開始到文本結尾的任何字符，包括中文和換行符
        $pattern = '/建物門牌(.*)/us';  // 使用 u 修飾符支持 UTF-8，使用 s 使 . 匹配包括換行符在內的任何字符
        preg_match($pattern, $utf8Text, $matches);

        if (isset($matches[1])) {
            $address = $matches[1];
            // Remove the first character
            $address = substr($address, 2);
            // Split at the newline and take only the first part
            $parts = explode("\n", $address);
            $address = $parts[0];
        } else {
            $address = '未找到';  // Default to '未找到' if no match
        }

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
