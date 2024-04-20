<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;

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

        $utf8Text     = $this->text;
        $encoding     = mb_detect_encoding($utf8Text, 'UTF-8, Big5', true);
        $encodingInfo = $encoding ?: 'Unknown';
        file_put_contents('utf8_text_output.txt', $utf8Text . "\nEncoding: " . $encodingInfo, FILE_APPEND);

        // 欄位與正則表達式的映射
        $fields = [
            '建物門牌'      => '建物門牌：(.*)',
            '建物坐落地號'  => '建物坐落㆞號：(.*)',
            '主要用途'      => '主要用途：(.*)',
            '主要建材'      => '主要建材：(.*)',
            '層數'          => '數：(.*)',
            '層次'          => '次：(.*)',
            '建築完成日期'  => '建築完成㈰期：(.*)',
            '附屬建物用途'  => '附屬建物用途：(.*)',
            '總面積'        => '總面積：(.*)',
            '層次面積'      => '層次面積：(.*)',
            '面積'          => '面積：(.*)',
            '登記日期'      => '登記㈰期：(.*)',
            '原因發生日期'  => '原因發生㈰期：(.*)',
            '所有權人'      => '所㈲權㆟：(.*)',
            '統一編號'      => '統㆒編號：(.*)',
            '住址'          => '址：(.*)',
            '權利範圍'      => '權利範圍：(.*)',
            '其他登記事項1' => '其他登記事㊠(.*)',
        ];

        foreach ($fields as $fieldTitle => $regex) {
            $pattern = '/' . $regex . '/us';
            preg_match($pattern, $utf8Text, $matches);
            $value = $matches[1] ?? '未找到';
            $parts = explode("\n", $value);
            $value = $parts[0];

            $index = array_search($fieldTitle, array_keys($fields)) + 1;
            $sheet->setCellValue('A' . $index, $fieldTitle);
            $sheet->setCellValue('B' . $index, $value);
        }

        // 設置邊框和列寬
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
        $sheet->getStyle('A1:B18')->applyFromArray($styleArray);
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(40);

        // 創建和保存 Excel 文件
        $writer   = new Xlsx($spreadsheet);
        $fileName = 'building_address.xlsx';
        $writer->save($fileName);

        return $fileName;
    }
}