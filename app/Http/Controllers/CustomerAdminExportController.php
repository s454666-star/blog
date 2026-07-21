<?php

namespace App\Http\Controllers;

use App\Models\CrmAddress;
use App\Models\CrmContact;
use App\Models\CrmCustomer;
use App\Models\CrmOrder;
use App\Models\CrmProduct;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerAdminExportController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->addSheet($spreadsheet, '客戶', ['客戶編號', '客戶名稱', '市話', '手機電話', '地址', '統編', '產業', 'Email', '網站', '狀態', '備註'],
            CrmCustomer::orderBy('id')->get()->map(fn ($r) => [$r->code, $r->name, $r->phone, $r->mobile, $r->address, $r->tax_id, $r->industry, $r->email, $r->website, $r->status, $r->notes])->all());
        $this->addSheet($spreadsheet, '接洽人', ['客戶', '姓名', '職稱', '部門', '電話', '手機', 'Email', '偏好聯絡', '備註'],
            CrmContact::with('customer')->orderBy('id')->get()->map(fn ($r) => [$r->customer?->name, $r->name, $r->title, $r->department, $r->phone, $r->mobile, $r->email, $r->preferred_contact, $r->notes])->all());
        $this->addSheet($spreadsheet, '地址', ['客戶', '標籤', '收件人', '電話', '郵遞區號', '縣市', '區域', '地址', '補充地址', '預設', '備註'],
            CrmAddress::with('customer')->orderBy('id')->get()->map(fn ($r) => [$r->customer?->name, $r->label, $r->recipient, $r->phone, $r->postal_code, $r->county, $r->district, $r->address_line1, $r->address_line2, $r->is_default ? '是' : '否', $r->notes])->all());
        $this->addSheet($spreadsheet, '商品', ['商品編號', '品名', '分類', '售價', '成本', '單位', '庫存', '稅率', '狀態', '圖片路徑', '說明'],
            CrmProduct::orderBy('id')->get()->map(fn ($r) => [$r->sku, $r->name, $r->category, $r->price, $r->cost, $r->unit, $r->stock_quantity, $r->tax_rate, $r->status, $r->image_path, $r->description])->all());
        $this->addSheet($spreadsheet, '訂單', ['訂單編號', '日期', '客戶', '接洽人', '付款狀態', '付款方式', '小計', '總額', '備註'],
            CrmOrder::with(['customer', 'contact'])->orderBy('id')->get()->map(fn ($r) => [$r->order_number, $r->order_date?->format('Y-m-d'), $r->customer?->name, $r->contact?->name, $r->payment_status, $r->payment_method, $r->subtotal, $r->total, $r->notes])->all());
        $this->addSheet($spreadsheet, '訂單明細', ['訂單編號', '商品', '數量', '單價', '小計', '備註'],
            CrmOrder::with('items')->orderBy('id')->get()->flatMap(fn ($order) => $order->items->map(fn ($item) => [$order->order_number, $item->product_name, $item->quantity, $item->unit_price, $item->line_total, $item->notes]))->all());

        $path = storage_path('app/customer-admin-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return response()->download($path, '客戶訂單管理_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    private function addSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setCellValue('A1', 'STAR CRM｜'.$title.'資料');
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A2', '匯出時間：'.now()->format('Y-m-d H:i:s'));
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->fromArray($headers, null, 'A4');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A5');
        }

        $lastRow = max(4, $sheet->getHighestRow());
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(4)->setRowHeight(25);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF5B4DFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF68708A']],
        ]);
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF252B4A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A4:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD8DCEF']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        for ($row = 5; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F1FF');
            }
        }
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastColumn}4");
        for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex++) {
            $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
        }
        $sheet->calculateColumnWidths();
        for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex++) {
            $dimension = $sheet->getColumnDimensionByColumn($columnIndex);
            $calculated = $dimension->getWidth();
            $dimension->setAutoSize(false);
            $dimension->setWidth(max(10, min(45, $calculated + 2)));
        }
    }
}
