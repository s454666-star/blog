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

        $sheet->setCellValue('A1', '建物門牌');
        $sheet->setCellValue('A2', '建物坐落地號');
        // ... 其他單元格的設置 ...
        $sheet->setCellValue('B1', $address);

        // 設置邊框
        $styleArray = [
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color'       => [ 'argb' => 'FF000000' ],
                ],
                'inside'  => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => [ 'argb' => 'FF000000' ],
                ],
            ],
        ];
        $sheet->getStyle('A1:B20')->applyFromArray($styleArray);

        // 設置 B 列的寬度
        $sheet->getColumnDimension('B')->setWidth(20);

        // 創建 Excel 文件
        $writer   = new Xlsx($spreadsheet);
        $fileName = 'building_address.xlsx';
        $writer->save($fileName);

        return $fileName;
    }
}
