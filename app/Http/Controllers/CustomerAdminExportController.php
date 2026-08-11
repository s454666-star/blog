<?php

namespace App\Http\Controllers;

use App\Models\CrmContact;
use App\Models\CrmCustomer;
use App\Models\CrmOrder;
use App\Models\CrmProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerAdminExportController extends Controller
{
    public function index(Request $request): View
    {
        $years = CrmOrder::query()
            ->whereNotNull('order_date')
            ->orderByDesc('order_date')
            ->get(['order_date'])
            ->map(fn (CrmOrder $order) => $order->order_date?->year)
            ->filter()
            ->unique()
            ->values();
        $contacts = CrmContact::query()->orderBy('name')->orderBy('id')->get();
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        $contactId = $request->filled('contact_id') ? (int) $request->query('contact_id') : null;
        $orders = null;

        if ($year || $contactId) {
            $orders = $this->orderQuery($year, $contactId)
                ->orderByDesc('order_date')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        return view('customer-admin.export', compact('years', 'contacts', 'year', 'contactId', 'orders'));
    }

    public function __invoke(Request $request): BinaryFileResponse
    {
        $filters = $request->validate([
            'mode' => ['nullable', Rule::in(['all', 'year', 'year_contact', 'contact_all', 'contact_sheets'])],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100', Rule::requiredIf(fn () => in_array($request->query('mode'), ['year', 'year_contact'], true))],
            'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id', Rule::requiredIf(fn () => in_array($request->query('mode'), ['year_contact', 'contact_all'], true))],
        ]);
        $mode = $filters['mode'] ?? 'all';
        $year = in_array($mode, ['year', 'year_contact'], true) ? (int) $filters['year'] : null;
        $contactId = in_array($mode, ['year_contact', 'contact_all', 'contact_sheets'], true) && isset($filters['contact_id'])
            ? (int) $filters['contact_id']
            : null;

        if ($mode === 'contact_sheets') {
            return $this->contactSheetsDownload($contactId);
        }
        $orders = $this->orderQuery($year, $contactId)
            ->with(['customer', 'contact', 'items'])
            ->orderBy('id')
            ->get();
        $customers = $mode === 'all'
            ? CrmCustomer::query()->orderBy('id')->get()
            : CrmCustomer::query()->whereIn('id', $orders->pluck('customer_id')->filter()->unique())->orderBy('id')->get();
        $contacts = CrmContact::query()
            ->when($mode !== 'all', fn (Builder $query) => $query->whereIn('id', $orders->pluck('contact_id')->filter()->unique()->when($contactId, fn (Collection $ids) => $ids->push($contactId))->unique()))
            ->with('customer')->orderBy('id')->get();
        $products = CrmProduct::query()
            ->when($mode !== 'all', fn (Builder $query) => $query->whereIn('id', $orders->flatMap->items->pluck('product_id')->filter()->unique()))
            ->orderBy('id')->get();
        $filterLabel = $this->filterLabel($mode, $year, $contactId);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->addSheet($spreadsheet, '客戶', ['客戶編號', '客戶名稱', '市話', '手機電話', '地址', '統編', '產業', '網站', '狀態', '備註'],
            $customers->map(fn ($r) => [$r->code, $r->name, $r->phone, $r->mobile, $r->address, $r->tax_id, $r->industry, $r->website, $r->status, $r->notes])->all(), [], $filterLabel);
        $this->addSheet($spreadsheet, '接洽人', ['客戶', '姓名', '職稱', '部門', '電話', '手機', '偏好聯絡', '備註'],
            $contacts->map(fn ($r) => [$r->customer?->name, $r->name, $r->title, $r->department, $r->phone, $r->mobile, $r->preferred_contact, $r->notes])->all(), [], $filterLabel);
        $this->addSheet($spreadsheet, '商品', ['商品編號', '品名', '分類', '售價', '成本', '單位', '庫存', '稅率', '狀態', '圖片路徑', '說明'],
            $products->map(fn ($r) => [$r->sku, $r->name, $r->category, $this->roundMoney($r->price), $r->cost === null ? null : $this->roundMoney($r->cost), $r->unit, $r->stock_quantity, $r->tax_rate, $r->status, $r->image_path, $r->description])->all(),
            ['D', 'E'], $filterLabel);
        $this->addSheet($spreadsheet, '訂單', ['訂單編號', '日期', '客戶', '接洽人', '付款狀態', '付款方式', '小計', '總額', '備註'],
            $orders->map(fn ($r) => [$r->order_number, $r->order_date?->format('Y-m-d'), $r->customer?->name, $r->contact?->name, $r->payment_status, $r->payment_method, $this->roundMoney($r->subtotal), $this->roundMoney($r->total), $r->notes])->all(),
            ['G', 'H'], $filterLabel);
        $this->addSheet($spreadsheet, '訂單明細', ['訂單編號', '商品', '數量', '單價', '小計', '備註'],
            $orders->flatMap(fn ($order) => $order->items->map(fn ($item) => [$order->order_number, $item->product_name, $item->quantity, $this->roundMoney($item->unit_price), $this->roundMoney($item->line_total), $item->notes]))->all(),
            ['D', 'E'], $filterLabel);

        $path = storage_path('app/customer-admin-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return response()->download($path, '客戶訂單管理_'.$this->filenameLabel($mode, $year, $contactId).'_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    private function orderQuery(?int $year = null, ?int $contactId = null): Builder
    {
        return CrmOrder::query()
            ->with(['customer', 'contact'])
            ->when($year, fn (Builder $query) => $query->whereYear('order_date', $year))
            ->when($contactId, fn (Builder $query) => $query->where('contact_id', $contactId));
    }

    private function contactSheetsDownload(?int $contactId): BinaryFileResponse
    {
        $contacts = CrmContact::query()
            ->when($contactId, fn (Builder $query) => $query->whereKey($contactId))
            ->with(['orders' => fn ($query) => $query
                ->with(['customer', 'items'])
                ->orderBy('order_date')
                ->orderBy('id')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $usedTitles = [];

        foreach ($contacts as $contact) {
            $this->addContactOrdersSheet(
                $spreadsheet,
                $this->uniqueSheetTitle($contact->name, $contact->id, $usedTitles),
                $contact->orders,
                '接洽人：'.$contact->name
            );
        }

        if (! $contactId) {
            $unassignedOrders = $this->orderQuery()
                ->whereNull('contact_id')
                ->with(['customer', 'items'])
                ->orderBy('order_date')
                ->orderBy('id')
                ->get();

            if ($unassignedOrders->isNotEmpty()) {
                $this->addContactOrdersSheet($spreadsheet, '未指定接洽人', $unassignedOrders, '接洽人：未指定');
            }
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $this->addContactOrdersSheet($spreadsheet, '無接洽人資料', collect(), '目前沒有接洽人資料');
        }

        $path = storage_path('app/customer-admin-contact-sheets-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $label = $contactId ? '接洽人'.$contactId : '全部接洽人';

        return response()->download($path, '客戶訂單管理_'.$label.'_分頁_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    private function addContactOrdersSheet(Spreadsheet $spreadsheet, string $title, Collection $orders, string $filterLabel): void
    {
        $rows = $orders->map(function (CrmOrder $order) {
            $items = $order->items->map(function ($item) {
                return $item->product_name.' × '.rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.');
            })->implode("\n");
            $phones = collect([$order->customer?->phone, $order->customer?->mobile])
                ->filter(fn ($phone) => filled($phone))
                ->unique()
                ->implode("\n");

            return [
                $order->order_date?->format('Y-m-d'),
                $order->customer?->name,
                $phones,
                $order->customer?->address,
                $items,
            ];
        })->all();

        $this->addSheet(
            $spreadsheet,
            $title,
            ['日期', '人名', '電話', '地址', '品項'],
            $rows,
            [],
            $filterLabel
        );
    }

    private function uniqueSheetTitle(string $name, int $contactId, array &$usedTitles): string
    {
        $base = trim(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $name) ?? '');
        $base = trim($base, " '");
        $base = mb_substr($base !== '' ? $base : '接洽人'.$contactId, 0, 31);
        $title = $base;
        $suffix = '-'.$contactId;

        if (isset($usedTitles[mb_strtolower($title)])) {
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        $usedTitles[mb_strtolower($title)] = true;

        return $title;
    }

    private function filterLabel(string $mode, ?int $year, ?int $contactId): string
    {
        $contact = $contactId ? CrmContact::find($contactId)?->name : null;

        return match ($mode) {
            'year' => "篩選條件：{$year} 年全部訂單",
            'year_contact' => "篩選條件：{$year} 年／接洽人 {$contact}",
            'contact_all' => "篩選條件：接洽人 {$contact}／全部年份",
            default => '篩選條件：全部資料',
        };
    }

    private function filenameLabel(string $mode, ?int $year, ?int $contactId): string
    {
        return match ($mode) {
            'year' => (string) $year,
            'year_contact' => $year.'_接洽人'.$contactId,
            'contact_all' => '接洽人'.$contactId.'_全部年份',
            default => '全部資料',
        };
    }

    private function addSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows, array $moneyColumns = [], string $filterLabel = '篩選條件：全部資料'): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setCellValue('A1', 'STAR CRM｜'.$title.'資料');
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A2', $filterLabel.'｜匯出時間：'.now()->format('Y-m-d H:i:s'));
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
        foreach ($moneyColumns as $column) {
            $sheet->getStyle("{$column}5:{$column}{$lastRow}")
                ->getNumberFormat()->setFormatCode('#,##0');
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

    private function roundMoney(int|float|string|null $value): int
    {
        return (int) round((float) $value, 0, PHP_ROUND_HALF_UP);
    }
}
