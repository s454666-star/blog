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

        $encoding     = mb_detect_encoding($utf8Text, 'UTF-8, Big5', true);
        $encodingInfo = $encoding ?: 'Unknown';

        file_put_contents('utf8_text_output.txt', $utf8Text . "\nEncoding: " . $encodingInfo, FILE_APPEND);
        // 搜索 "建物門牌:" 開始到文本結尾的任何字符，包括中文和換行符
        $pattern = '/建物門牌(.*)/us';  // 使用 u 修飾符支持 UTF-8，使用 s 使 . 匹配包括換行符在內的任何字符
        preg_match($pattern, $utf8Text, $matches);

        if (isset($matches[1])) {
            $address = $matches[1];
            // Remove the first character
            $address = substr($address, 3);
            // Split at the newline and take only the first part
            $parts   = explode("\n", $address);
            $address = $parts[0];
        } else {
            $address = '未找到';  // Default to '未找到' if no match
        }

        // 設置標題和數據
        $sheet->setCellValue('A1', '建物門牌');
        $sheet->setCellValue('B1', '建物坐落地號');
        $sheet->setCellValue('C1', '主要用途');
        $sheet->setCellValue('D1', '主要建材');
        $sheet->setCellValue('E1', '層數');
        $sheet->setCellValue('F1', '層次');
        $sheet->setCellValue('G1', '建築完成日期');
        $sheet->setCellValue('H1', '附屬建物用途');
        $sheet->setCellValue('I1', '其他登記事項');
        $sheet->setCellValue('J1', '總面積');
        $sheet->setCellValue('K1', '層次面積');
        $sheet->setCellValue('L1', '面積');
        $sheet->setCellValue('M1', '登記日期');
        $sheet->setCellValue('N1', '原因發生日期');
        $sheet->setCellValue('O1', '所有權人');
        $sheet->setCellValue('P1', '統一編號');
        $sheet->setCellValue('Q1', '住址');
        $sheet->setCellValue('R1', '權利範圍');
        $sheet->setCellValue('S1', '其他登記事項');
        $sheet->setCellValue('M1', '其他登記事項');

        $sheet->setCellValue('B1', $address);

        // 創建 Excel 文件
        $writer   = new Xlsx($spreadsheet);
        $fileName = 'building_address.xlsx';
        $writer->save($fileName);

        return $fileName;
    }
}
