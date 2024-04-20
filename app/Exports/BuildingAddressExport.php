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

        // 搜索 "建物門牌:" 開始到文本結尾
        $pattern = '/建物門牌:(.*)/';
        preg_match($pattern, $this->text, $matches);

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
