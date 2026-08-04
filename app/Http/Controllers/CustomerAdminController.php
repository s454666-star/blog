<?php

namespace App\Http\Controllers;

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
                ['label' => '訂單總數', 'value' => CrmOrder::count(), 'icon' => '▣', 'tone' => 'cyan'],
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
        $perPageOptions = [20, 50, 100, 200];
        $perPage = (int) $request->query('per_page', 20);
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($subQuery) use ($config, $search) {
                foreach ($config['search'] as $index => $field) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $subQuery->{$method}($field, 'like', '%'.$search.'%');
                }

                foreach ($config['relationship_search'] ?? [] as $relation => $fields) {
                    $subQuery->orWhereHas($relation, function ($relationQuery) use ($fields, $search) {
                        $relationQuery->where(function ($fieldQuery) use ($fields, $search) {
                            foreach ($fields as $index => $field) {
                                $method = $index === 0 ? 'where' : 'orWhere';
                                $fieldQuery->{$method}($field, 'like', '%'.$search.'%');
                            }
                        });
                    });
                }
            });
        }

        $sort = (string) $request->query('sort');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $sortable = $config['sortable'] ?? [];

        if (isset($sortable[$sort])) {
            $sortColumn = $sortable[$sort];

            if ($sortColumn === 'customer.name') {
                $query->orderBy(
                    CrmCustomer::query()
                        ->select('name')
                        ->whereColumn('crm_customers.id', 'crm_orders.customer_id'),
                    $direction
                );
            } else {
                $query->orderBy($sortColumn, $direction);
            }

            $query->orderByDesc($query->getModel()->qualifyColumn('id'));
        } elseif ($module === 'products') {
            $query->orderBy('sort_order')->orderBy('id');
        } else {
            $query->latest();
        }

        return view('customer-admin.index', [
            'module' => $module,
            'config' => $config,
            'records' => $query->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
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
        $this->normalizeOrderDateInput($request, $module);
        $data = $request->validate($this->rules($module));
        $data = $this->roundMoneyValues($module, $data);

        DB::transaction(function () use ($module, $config, $data) {
            if ($module === 'orders') {
                $this->saveOrder(new CrmOrder, $data);
            } else {
                if ($module === 'products') {
                    $data = $this->prepareProductImage(request(), $data);
                    $data['sort_order'] = ((int) CrmProduct::max('sort_order')) + 1;
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
        $this->normalizeOrderDateInput($request, $module);
        $data = $request->validate($this->rules($module, $record));
        $data = $this->roundMoneyValues($module, $data);

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

    public function moveProduct(Request $request, int $id): RedirectResponse
    {
        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'];

        DB::transaction(function () use ($id, $direction) {
            $products = CrmProduct::query()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $currentIndex = $products->search(fn (CrmProduct $product) => $product->id === $id);
            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;

            if ($currentIndex === false || ! $products->has($targetIndex)) {
                return;
            }

            [$products[$currentIndex], $products[$targetIndex]] = [$products[$targetIndex], $products[$currentIndex]];
            $products->values()->each(fn (CrmProduct $product, int $index) => $product->update(['sort_order' => $index + 1]));
        });

        return back()->with('success', '商品順序已自動儲存。');
    }

    private function normalizeOrderDateInput(Request $request, string $module): void
    {
        if ($module !== 'orders' || blank($request->input('order_date'))) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $request->input('order_date'));
        $date = strlen($digits) === 8 ? \DateTimeImmutable::createFromFormat('!Ymd', $digits) : false;
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Ymd') === $digits) {
            $request->merge(['order_date' => $date->format('Y-m-d')]);
        }
    }

    private function saveOrder(CrmOrder $order, array $data): void
    {
        $items = collect($data['items'])->map(function (array $item): array {
            $item['unit_price'] = $this->roundMoney($item['unit_price']);
            $item['line_total'] = $this->roundMoney((float) $item['quantity'] * $item['unit_price']);

            return $item;
        })->all();
        unset($data['items']);

        $customerData = [
            'name' => $data['customer_name'],
            'phone' => $data['customer_phone'] ?? null,
            'mobile' => $data['customer_mobile'] ?? null,
            'address' => $data['customer_address'] ?? null,
            'tax_id' => $data['customer_tax_id'] ?? null,
            'notes' => $data['customer_notes'] ?? null,
        ];
        $customerId = $data['customer_id'] ?? null;
        foreach (array_keys($customerData) as $field) {
            unset($data['customer_'.$field]);
        }

        // Orders never modify existing customer records. Reuse an explicitly
        // selected customer only when every customer field is unchanged;
        // otherwise preserve the old record and create a new customer snapshot.
        $selectedCustomer = $customerId ? CrmCustomer::find($customerId) : null;
        $selectedCustomerIsUnchanged = $selectedCustomer
            && collect($customerData)->every(
                fn ($value, $field) => $selectedCustomer->getAttribute($field) === $value
            );
        $customer = $selectedCustomerIsUnchanged
            ? $selectedCustomer
            : CrmCustomer::create($customerData);
        $data['customer_id'] = $customer->id;

        $subtotal = collect($items)->sum('line_total');
        $discount = $order->exists ? $this->roundMoney($order->discount) : 0;
        $shipping = $order->exists ? $this->roundMoney($order->shipping_fee) : 0;
        $tax = $order->exists ? $this->roundMoney($order->tax) : 0;

        if (! $order->exists) {
            $data['order_number'] = ($data['order_number'] ?? null)
                ?: 'ORD-'.now()->format('Ymd-His').'-'.random_int(10, 99);
        }
        $data['subtotal'] = $this->roundMoney($subtotal);
        $data['total'] = max(0, $this->roundMoney($subtotal - $discount + $shipping + $tax));
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
                'line_total' => $item['line_total'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function roundMoneyValues(string $module, array $data): array
    {
        if ($module === 'products') {
            foreach (['price', 'cost'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $data[$field] = $this->roundMoney($data[$field]);
                }
            }
        }

        return $data;
    }

    private function roundMoney(int|float|string|null $value): int
    {
        return (int) round((float) $value, 0, PHP_ROUND_HALF_UP);
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
                'preferred_contact' => ['nullable', 'string', 'max:30'],
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
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'customer_mobile' => ['nullable', 'string', 'max:50'],
                'customer_address' => ['nullable', 'string', 'max:255'],
                'customer_tax_id' => ['nullable', 'string', 'max:20'],
                'customer_notes' => ['nullable', 'string'],
                'contact_id' => ['nullable', 'exists:crm_contacts,id'],
                'order_date' => ['nullable', 'date'],
                'payment_status' => ['nullable', 'string', 'max:30'],
                'payment_method' => ['nullable', 'string', 'max:30'],
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
        $contacts = CrmContact::with('customer')->orderBy('name')->orderBy('id')->get();

        return [
            'customers' => CrmCustomer::orderBy('name')->pluck('name', 'id')->all(),
            'contacts' => $contacts
                ->mapWithKeys(fn ($item) => [$item->id => $item->name.($item->customer ? '｜'.$item->customer->name : '')])->all(),
            'defaultContactId' => $contacts->firstWhere('name', '陳威仁')?->id,
            'products' => CrmProduct::orderBy('sort_order')->orderBy('id')->get()
                ->mapWithKeys(fn ($item) => [$item->id => ['label' => $item->name.'｜$'.number_format($this->roundMoney($item->price)), 'name' => $item->name, 'price' => $this->roundMoney($item->price)]])->all(),
            'cityPhones' => CrmCustomer::query()->whereNotNull('phone')->where('phone', '!=', '')
                ->distinct()->orderBy('phone')->pluck('phone')->all(),
            'mobilePhones' => CrmCustomer::query()->whereNotNull('mobile')->where('mobile', '!=', '')
                ->distinct()->orderBy('mobile')->pluck('mobile')->all(),
            'orderCustomers' => CrmCustomer::orderBy('name')->get()
                ->map(function (CrmCustomer $customer) {
                    return [
                        'id' => $customer->id,
                        'code' => $customer->code,
                        'name' => $customer->name,
                        'tax_id' => $customer->tax_id,
                        'industry' => $customer->industry,
                        'phone' => $customer->phone,
                        'mobile' => $customer->mobile,
                        'customer_address' => $customer->address,
                        'website' => $customer->website,
                        'status' => $customer->status,
                        'notes' => $customer->notes,
                    ];
                })->values()->all(),
        ];
    }

    private function module(string $module): array
    {
        $modules = [
            'customers' => [
                'title' => '客戶管理', 'singular' => '客戶', 'model' => CrmCustomer::class, 'with' => [],
                'search' => ['name', 'code', 'tax_id', 'phone', 'mobile', 'address'],
                'columns' => ['code' => '客戶編號', 'name' => '客戶名稱', 'phone' => '市話', 'mobile' => '手機電話', 'address' => '地址', 'status' => '狀態'],
                'fields' => [
                    'code' => ['label' => '客戶編號', 'placeholder' => '例如 C-001'],
                    'name' => ['label' => '客戶名稱', 'required' => true],
                    'phone' => ['label' => '市話', 'datalist' => 'cityPhones'],
                    'mobile' => ['label' => '手機電話', 'datalist' => 'mobilePhones'],
                    'address' => ['label' => '地址', 'wide' => true],
                    'tax_id' => ['label' => '統一編號'],
                    'industry' => ['label' => '產業類別'],
                    'website' => ['label' => '網站', 'type' => 'url'],
                    'status' => ['label' => '客戶狀態', 'type' => 'select', 'options' => ['潛在客戶' => '潛在客戶', '洽談中' => '洽談中', '合作中' => '合作中', '暫停' => '暫停']],
                    'notes' => ['label' => '備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'contacts' => [
                'title' => '接洽人管理', 'singular' => '接洽人', 'model' => CrmContact::class, 'with' => ['customer'],
                'search' => ['name', 'title', 'department', 'phone', 'mobile'],
                'columns' => ['name' => '姓名', 'customer.name' => '所屬客戶', 'title' => '職稱', 'mobile' => '手機'],
                'fields' => [
                    'customer_id' => ['label' => '所屬客戶', 'type' => 'relation', 'source' => 'customers'],
                    'name' => ['label' => '接洽人姓名', 'required' => true],
                    'title' => ['label' => '職稱'],
                    'department' => ['label' => '部門'],
                    'phone' => ['label' => '公司電話'],
                    'mobile' => ['label' => '行動電話'],
                    'preferred_contact' => ['label' => '偏好聯絡方式', 'type' => 'select', 'options' => ['電話' => '電話', '手機' => '手機', 'LINE' => 'LINE']],
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
                    'price' => ['label' => '售價', 'type' => 'number', 'step' => '1', 'required' => true],
                    'cost' => ['label' => '成本', 'type' => 'number', 'step' => '1'],
                    'unit' => ['label' => '單位', 'type' => 'select', 'options' => ['個' => '個', '件' => '件', '組' => '組', '盒' => '盒', '包' => '包', '罐' => '罐', '箱' => '箱', '公斤' => '公斤']],
                    'stock_quantity' => ['label' => '庫存數量', 'type' => 'number', 'step' => '0.01'],
                    'tax_rate' => ['label' => '稅率 (%)', 'type' => 'number', 'step' => '0.01'],
                    'status' => ['label' => '商品狀態', 'type' => 'select', 'options' => ['販售中' => '販售中', '暫停販售' => '暫停販售', '停售' => '停售']],
                    'description' => ['label' => '商品說明', 'type' => 'textarea', 'wide' => true],
                ],
            ],
            'orders' => [
                'title' => '訂單管理', 'singular' => '訂單', 'model' => CrmOrder::class, 'with' => ['customer', 'items'],
                'search' => ['order_number', 'payment_status', 'notes'],
                'relationship_search' => ['customer' => ['name', 'phone', 'mobile', 'address']],
                'sortable' => [
                    'order_number' => 'order_number',
                    'order_date' => 'order_date',
                    'customer.name' => 'customer.name',
                    'payment_status' => 'payment_status',
                    'total' => 'total',
                ],
                'columns' => ['order_number' => '訂單編號', 'order_date' => '訂單日期', 'customer.name' => '客戶', 'payment_status' => '付款', 'total' => '總額'],
                'fields' => [
                    'order_number' => ['label' => '訂單編號', 'placeholder' => '留空自動產生'],
                    'order_date' => ['label' => '訂單日期', 'type' => 'date'],
                    'contact_id' => ['label' => '接洽人', 'type' => 'relation', 'source' => 'contacts'],
                    'payment_status' => ['label' => '付款狀態', 'type' => 'select', 'default' => '已付款', 'options' => ['未付款' => '未付款', '部分付款' => '部分付款', '已付款' => '已付款', '已退款' => '已退款']],
                    'payment_method' => ['label' => '付款方式', 'type' => 'select', 'default' => '銀行轉帳', 'options' => ['現金' => '現金', '銀行轉帳' => '銀行轉帳', '信用卡' => '信用卡', '月結' => '月結', '貨到付款' => '貨到付款']],
                    'notes' => ['label' => '訂單備註', 'type' => 'textarea', 'wide' => true],
                ],
            ],
        ];

        abort_unless(isset($modules[$module]), 404);

        return $modules[$module];
    }
}
