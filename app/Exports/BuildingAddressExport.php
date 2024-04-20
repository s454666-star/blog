<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Style;

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

        $pattern = '/建物門牌(.*)/us';
        preg_match($pattern, $utf8Text, $matches);

        if (isset($matches[1])) {
            $address = $matches[1];
            $address = substr($address, 3);
            $parts   = explode("\n", $address);
            $address = $parts[0];
        } else {
            $address = '未找到';
        }

        $pattern = '/建物坐落地號(.*)/us';
        preg_match($pattern, $utf8Text, $matches);
        if (isset($matches[1])) {
            $locationNumber = substr($matches[1], 3);
            $locationNumber = str_replace("\n", "", $locationNumber);  // 去掉換行符
        } else {
            $locationNumber = '未找到';
        }

        // 設置標題和數據
        $sheet->setCellValue('A1', '建物門牌');
        $sheet->setCellValue('A2', '建物坐落地號');
        $sheet->setCellValue('A3', '主要用途');
        $sheet->setCellValue('A4', '主要建材');
        $sheet->setCellValue('A5', '層數');
        $sheet->setCellValue('A6', '層次');
        $sheet->setCellValue('A7', '建築完成日期');
        $sheet->setCellValue('A8', '附屬建物用途');
        $sheet->setCellValue('A9', '其他登記事項');
        $sheet->setCellValue('A10', '總面積');
        $sheet->setCellValue('A11', '層次面積');
        $sheet->setCellValue('A12', '面積');
        $sheet->setCellValue('A13', '登記日期');
        $sheet->setCellValue('A14', '原因發生日期');
        $sheet->setCellValue('A15', '所有權人');
        $sheet->setCellValue('A16', '統一編號');
        $sheet->setCellValue('A17', '住址');
        $sheet->setCellValue('A18', '權利範圍');
        $sheet->setCellValue('A19', '其他登記事項1');
        $sheet->setCellValue('A20', '其他登記事項2');

        $sheet->setCellValue('B1', $address);
        $sheet->setCellValue('B2', $locationNumber);

        // 設置邊框
        $styleArray = [
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color' => ['argb' => 'FF000000'],
                ],
                'inside' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ];
        $sheet->getStyle('A1:B20')->applyFromArray($styleArray);

        // 設置 B 列的寬度
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(20);

        // 創建 Excel 文件
        $writer   = new Xlsx($spreadsheet);
        $fileName = 'building_address.xlsx';
        $writer->save($fileName);

        return $fileName;
    }
}
