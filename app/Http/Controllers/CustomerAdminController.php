<?php

namespace App\Http\Controllers;

use App\Models\CrmAddress;
use App\Models\CrmContact;
use App\Models\CrmCustomer;
use App\Models\CrmOrder;
use App\Models\CrmOrderItem;
use App\Models\CrmProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerAdminController extends Controller
{
    public function dashboard(): View
    {
        return view('customer-admin.dashboard', [
            'stats' => [
                ['label' => '客戶總數', 'value' => CrmCustomer::count(), 'icon' => '◎', 'tone' => 'cyan'],
                ['label' => '接洽人', 'value' => CrmContact::count(), 'icon' => '◇', 'tone' => 'violet'],
                ['label' => '商品品項', 'value' => CrmProduct::count(), 'icon' => '◆', 'tone' => 'amber'],
                ['label' => '訂單總額', 'value' => '$'.number_format((float) CrmOrder::sum('total')), 'icon' => '✦', 'tone' => 'emerald'],
            ],
            'recentOrders' => CrmOrder::with('customer')->latest()->limit(8)->get(),
        ]);
    }

    public function index(Request $request, string $module): View
    {
        $config = $this->module($module);
        $query = $config['model']::query()->with($config['with']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($subQuery) use ($config, $search) {
                foreach ($config['search'] as $index => $field) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $subQuery->{$method}($field, 'like', '%'.$search.'%');
                }
            });
        }

        return view('customer-admin.index', [
            'module' => $module,
            'config' => $config,
            'records' => $query->latest()->paginate(15)->withQueryString(),
        ]);
    }

    public function create(string $module): View
    {
        $config = $this->module($module);

        return view('customer-admin.form', [
            'module' => $module,
            'config' => $config,
            'record' => new $config['model'],
            'options' => $this->formOptions(),
        ]);
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $config = $this->module($module);
        $data = $request->validate($this->rules($module));

        DB::transaction(function () use ($module, $config, $data) {
            if ($module === 'orders') {
                $this->saveOrder(new CrmOrder, $data);
            } else {
                if ($module === 'products') {
                    $data = $this->prepareProductImage(request(), $data);
                }
                $config['model']::create($data);
            }
        });

        return redirect()->route('customer-admin.module.index', $module)
            ->with('success', $config['singular'].'已新增。');
    }

    public function edit(string $module, int $id): View
    {
        $config = $this->module($module);
        $record = $config['model']::with($config['with'])->findOrFail($id);

        return view('customer-admin.form', [
            'module' => $module,
            'config' => $config,
            'record' => $record,
            'options' => $this->formOptions(),
        ]);
    }

    public function update(Request $request, string $module, int $id): RedirectResponse
    {
        $config = $this->module($module);
        $record = $config['model']::findOrFail($id);
        $data = $request->validate($this->rules($module, $record));

        DB::transaction(function () use ($module, $record, $data) {
            if ($module === 'orders') {
                $this->saveOrder($record, $data);
            } else {
                if ($module === 'products') {
                    $data = $this->prepareProductImage(request(), $data, $record);
                }
                $record->update($data);
            }
        });

        return redirect()->route('customer-admin.module.index', $module)
            ->with('success', $config['singular'].'已更新。');
    }

    public function destroy(string $module, int $id): RedirectResponse
    {
        $config = $this->module($module);
        $record = $config['model']::findOrFail($id);
        if ($module === 'products' && $record->image_path) {
            Storage::disk('public')->delete($record->image_path);
        }
        $record->delete();

        return back()->with('success', $config['singular'].'已刪除。');
    }

    private function saveOrder(CrmOrder $order, array $data): void
    {
        $items = $data['items'];
        unset($data['items']);

        $subtotal = collect($items)->sum(fn ($item) => (float) $item['quantity'] * (float) $item['unit_price']);
        $discount = (float) ($data['discount'] ?? 0);
        $shipping = (float) ($data['shipping_fee'] ?? 0);
        $tax = (float) ($data['tax'] ?? 0);

        if (! $order->exists) {
            $data['order_number'] = ($data['order_number'] ?? null)
                ?: 'ORD-'.now()->format('Ymd-His').'-'.random_int(10, 99);
        }
        $data['subtotal'] = $subtotal;
        $data['total'] = max(0, $subtotal - $discount + $shipping + $tax);
        $order->fill($data)->save();
        $order->items()->delete();

        foreach ($items as $item) {
            $product = CrmProduct::find($item['product_id']);
            CrmOrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product?->id,
                'product_name' => $product?->name ?? $item['product_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => (float) $item['quantity'] * (float) $item['unit_price'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function prepareProductImage(Request $request, array $data, ?Model $record = null): array
    {
        unset($data['image'], $data['remove_image']);

        if ($request->boolean('remove_image') && $record?->image_path) {
            Storage::disk('public')->delete($record->image_path);
            $data['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($record?->image_path) {
                Storage::disk('public')->delete($record->image_path);
            }
            $data['image_path'] = $request->file('image')->store('customer-admin/products', 'public');
        }

        return $data;
    }

    private function rules(string $module, ?Model $record = null): array
    {
        return match ($module) {
            'customers' => [
                'code' => ['nullable', 'string', 'max:50', Rule::unique('crm_customers', 'code')->ignore($record?->id)],
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'mobile' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:255'],
                'tax_id' => ['nullable', 'string', 'max:20'],
                'industry' => ['nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
                'status' => ['nullable', 'string', 'max:30'],
                'notes' => ['nullable', 'string'],
            ],
            'contacts' => [
                'customer_id' => ['nullable', 'exists:crm_customers,id'],
                'name' => ['required', 'string', 'max:255'],
                'title' => ['nullable', 'string', 'max:100'],
                'department' => ['nullable', 'string', 'max:100'],
                'phone' => ['nullable', 'string', 'max:50'],
                'mobile' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],
                'preferred_contact' => ['nullable', 'string', 'max:30'],
                'notes' => ['nullable', 'string'],
            ],
            'addresses' => [
                'customer_id' => ['nullable', 'exists:crm_customers,id'],
                'label' => ['nullable', 'string', 'max:100'],
                'recipient' => ['nullable', 'string', 'max:100'],
                'phone' => ['nullable', 'string', 'max:50'],
                'postal_code' => ['nullable', 'string', 'max:20'],
                'county' => ['nullable', 'string', 'max:50'],
                'district' => ['nullable', 'string', 'max:50'],
                'address_line1' => ['nullable', 'string', 'max:255'],
                'address_line2' => ['nullable', 'string', 'max:255'],
                'is_default' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string'],
            ],
            'products' => [
                'sku' => ['nullable', 'string', 'max:80', Rule::unique('crm_products', 'sku')->ignore($record?->id)],
                'name' => ['required', 'string', 'max:255'],
                'category' => ['nullable', 'string', 'max:100'],
                'price' => ['required', 'numeric', 'min:0'],
                'cost' => ['nullable', 'numeric', 'min:0'],
                'unit' => ['nullable', 'string', 'max:30'],
                'stock_quantity' => ['nullable', 'numeric'],
                'tax_rate' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'string', 'max:30'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
                'remove_image' => ['nullable', 'boolean'],
                'description' => ['nullable', 'string'],
            ],
            'orders' => [
                'order_number' => ['nullable', 'string', 'max:60', Rule::unique('crm_orders', 'order_number')->ignore($record?->id)],
                'customer_id' => ['nullable', 'exists:crm_customers,id'],
                'contact_id' => ['nullable', 'exists:crm_contacts,id'],
                'address_id' => ['nullable', 'exists:crm_addresses,id'],
                'order_date' => ['nullable', 'date'],
                'status' => ['nullable', 'string', 'max:30'],
                'payment_status' => ['nullable', 'string', 'max:30'],
                'payment_method' => ['nullable', 'string', 'max:30'],
                'discount' => ['nullable', 'numeric', 'min:0'],
                'shipping_fee' => ['nullable', 'numeric', 'min:0'],
                'tax' => ['nullable', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.product_id' => ['required', 'exists:crm_products,id'],
                'items.*.product_name' => ['nullable', 'string', 'max:255'],
                'items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
                'items.*.notes' => ['nullable', 'string'],
            ],
            default => abort(404),
        };
    }

    private function formOptions(): array
    {
        return [
            'customers' => CrmCustomer::orderBy('name')->pluck('name', 'id')->all(),
            'contacts' => CrmContact::with('customer')->orderBy('name')->get()
                ->mapWithKeys(fn ($item) => [$item->id => $item->name.($item->customer ? '｜'.$item->customer->name : '')])->all(),
            'addresses' => CrmAddress::with('customer')->latest()->get()
                ->mapWithKeys(fn ($item) => [$item->id => ($item->label ?: '地址 #'.$item->id).'｜'.$item->full_address])->all(),
            'products' => CrmProduct::orderBy('name')->get()
                ->mapWithKeys(fn ($item) => [$item->id => ['label' => $item->name.'｜$'.number_format((float) $item->price), 'name' => $item->name, 'price' => $item->price]])->all(),
            'cityPhones' => CrmCustomer::query()->whereNotNull('phone')->where('phone', '!=', '')
                ->distinct()->orderBy('phone')->pluck('phone')->all(),
            'mobilePhones' => CrmCustomer::query()->whereNotNull('mobile')->where('mobile', '!=', '')
                ->distinct()->orderBy('mobile')->pluck('mobile')->all(),
            'orderCustomers' => CrmCustomer::with(['contacts', 'addresses'])->orderBy('name')->get()
                ->map(function (CrmCustomer $customer) {
                    $contact = $customer->contacts->first();
                    $address = $customer->addresses->firstWhere('is_default', true)
                        ?? $customer->addresses->first();

                    return [
                        'id' => $customer->id,
                        'code' => $customer->code,
                        'name' => $customer->name,
                        'tax_id' => $customer->tax_id,
                        'industry' => $customer->industry,
                        'phone' => $customer->phone,
                        'mobile' => $customer->mobile,
                        'customer_address' => $customer->address,
                        'email' => $customer->email,
                        'website' => $customer->website,
                        'status' => $customer->status,
                        'contact_id' => $contact?->id,
                        'contact' => $contact?->name,
                        'address_id' => $address?->id,
                        'address' => $address?->full_address,
                    ];
                })->values()->all(),
        ];
    }

    private function module(string $module): array
    {
        $modules = [
            'customers' => [
                'title' => '客戶管理', 'singular' => '客戶', 'model' => CrmCustomer::class, 'with' => [],
                'search' => ['name', 'code', 'tax_id', 'phone', 'mobile', 'address', 'email'],
                'columns' => ['code' => '客戶編號', 'name' => '客戶名稱', 'phone' => '市話', 'mobile' => '手機電話', 'address' => '地址', 'status' => '狀態'],
                'fields' => [
                    'code' => ['label' => '客戶編號', 'placeholder' => '例如 C-001'],
                    'name' => ['label' => '客戶名稱', 'required' => true],
                    'phone' => ['label' => '市話', 'datalist' => 'cityPhones'],
                    'mobile' => ['label' => '手機電話', 'datalist' => 'mobilePhones'],
                    'address' => ['label' => '地址', 'wide' => true],
                    'tax_id' => ['label' => '統一編號'],
                    'industry' => ['label' => '產業類別'],
                    'email' => ['label' => '電子信箱', 'type' => 'email'],
                    'website' => ['label' => '網站', 'type' => 'url'],
                    'status' => ['label' => '客戶狀態', 'type' => 'select', 'options' => ['潛在客戶' => '潛在客戶', '洽談中' => '洽談中', '合作中' => '合作中', '暫停' => '暫停']],
                    'notes' => ['label' => '備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'contacts' => [
                'title' => '接洽人管理', 'singular' => '接洽人', 'model' => CrmContact::class, 'with' => ['customer'],
                'search' => ['name', 'title', 'department', 'phone', 'mobile', 'email'],
                'columns' => ['name' => '姓名', 'customer.name' => '所屬客戶', 'title' => '職稱', 'mobile' => '手機', 'email' => 'Email'],
                'fields' => [
                    'customer_id' => ['label' => '所屬客戶', 'type' => 'relation', 'source' => 'customers'],
                    'name' => ['label' => '接洽人姓名', 'required' => true],
                    'title' => ['label' => '職稱'],
                    'department' => ['label' => '部門'],
                    'phone' => ['label' => '公司電話'],
                    'mobile' => ['label' => '行動電話'],
                    'email' => ['label' => '電子信箱', 'type' => 'email'],
                    'preferred_contact' => ['label' => '偏好聯絡方式', 'type' => 'select', 'options' => ['電話' => '電話', '手機' => '手機', 'Email' => 'Email', 'LINE' => 'LINE']],
                    'notes' => ['label' => '備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'addresses' => [
                'title' => '地址管理', 'singular' => '地址', 'model' => CrmAddress::class, 'with' => ['customer'],
                'search' => ['label', 'recipient', 'phone', 'postal_code', 'county', 'district', 'address_line1'],
                'columns' => ['label' => '地址標籤', 'customer.name' => '客戶', 'recipient' => '收件人', 'full_address' => '完整地址', 'is_default' => '預設'],
                'fields' => [
                    'customer_id' => ['label' => '所屬客戶', 'type' => 'relation', 'source' => 'customers'],
                    'label' => ['label' => '地址標籤', 'placeholder' => '例如：公司、倉庫'],
                    'recipient' => ['label' => '收件人'],
                    'phone' => ['label' => '收件電話'],
                    'postal_code' => ['label' => '郵遞區號'],
                    'county' => ['label' => '縣市'],
                    'district' => ['label' => '鄉鎮市區'],
                    'address_line1' => ['label' => '地址'],
                    'address_line2' => ['label' => '樓層／補充地址'],
                    'is_default' => ['label' => '設為預設', 'type' => 'select', 'options' => ['1' => '是', '0' => '否']],
                    'notes' => ['label' => '備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'products' => [
                'title' => '商品管理', 'singular' => '商品', 'model' => CrmProduct::class, 'with' => [],
                'search' => ['name', 'sku', 'category', 'description'],
                'columns' => ['sku' => '商品編號', 'name' => '品名', 'category' => '分類', 'price' => '售價', 'stock_quantity' => '庫存', 'status' => '狀態'],
                'fields' => [
                    'sku' => ['label' => '商品編號'],
                    'name' => ['label' => '品名', 'required' => true],
                    'category' => ['label' => '商品分類'],
                    'price' => ['label' => '售價', 'type' => 'number', 'step' => '0.01', 'required' => true],
                    'cost' => ['label' => '成本', 'type' => 'number', 'step' => '0.01'],
                    'unit' => ['label' => '單位', 'type' => 'select', 'options' => ['個' => '個', '件' => '件', '組' => '組', '盒' => '盒', '包' => '包', '罐' => '罐', '箱' => '箱', '公斤' => '公斤']],
                    'stock_quantity' => ['label' => '庫存數量', 'type' => 'number', 'step' => '0.01'],
                    'tax_rate' => ['label' => '稅率 (%)', 'type' => 'number', 'step' => '0.01'],
                    'status' => ['label' => '商品狀態', 'type' => 'select', 'options' => ['販售中' => '販售中', '暫停販售' => '暫停販售', '停售' => '停售']],
                    'description' => ['label' => '商品說明', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'orders' => [
                'title' => '訂單管理', 'singular' => '訂單', 'model' => CrmOrder::class, 'with' => ['customer', 'items'],
                'search' => ['order_number', 'status', 'payment_status', 'notes'],
                'columns' => ['order_number' => '訂單編號', 'order_date' => '訂單日期', 'customer.name' => '客戶', 'status' => '狀態', 'payment_status' => '付款', 'total' => '總額'],
                'fields' => [
                    'order_number' => ['label' => '訂單編號', 'placeholder' => '留空自動產生'],
                    'order_date' => ['label' => '訂單日期', 'type' => 'date'],
                    'customer_id' => ['label' => '客戶', 'type' => 'relation', 'source' => 'customers'],
                    'contact_id' => ['label' => '接洽人', 'type' => 'relation', 'source' => 'contacts'],
                    'address_id' => ['label' => '配送地址', 'type' => 'relation', 'source' => 'addresses'],
                    'status' => ['label' => '訂單狀態', 'type' => 'select', 'options' => ['草稿' => '草稿', '已確認' => '已確認', '備貨中' => '備貨中', '已出貨' => '已出貨', '已完成' => '已完成', '已取消' => '已取消']],
                    'payment_status' => ['label' => '付款狀態', 'type' => 'select', 'options' => ['未付款' => '未付款', '部分付款' => '部分付款', '已付款' => '已付款', '已退款' => '已退款']],
                    'payment_method' => ['label' => '付款方式', 'type' => 'select', 'options' => ['現金' => '現金', '銀行轉帳' => '銀行轉帳', '信用卡' => '信用卡', '月結' => '月結', '貨到付款' => '貨到付款']],
                    'discount' => ['label' => '折扣', 'type' => 'number', 'step' => '0.01'],
                    'shipping_fee' => ['label' => '運費', 'type' => 'number', 'step' => '0.01'],
                    'tax' => ['label' => '稅額', 'type' => 'number', 'step' => '0.01'],
                    'notes' => ['label' => '訂單備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
        ];

        abort_unless(isset($modules[$module]), 404);

        return $modules[$module];
    }
}
