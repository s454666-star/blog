<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Tests\TestCase;

class CustomerAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        (require database_path('migrations/2026_07_20_120000_create_customer_admin_tables.php'))->up();
        (require database_path('migrations/2026_07_20_180000_add_mobile_and_address_to_crm_customers.php'))->up();
        (require database_path('migrations/2026_07_22_120000_add_sort_order_to_crm_products.php'))->up();

        config()->set('customer-admin.username', 'test-admin');
        config()->set('customer-admin.password_hash', Hash::make('test-password'));
        Storage::fake('public');
    }

    public function test_login_crud_image_order_and_xlsx_export_work_together(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin');
        $this->post('/admin/login', ['username' => 'test-admin', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');

        $this->post('/admin/login', ['username' => 'test-admin', 'password' => 'test-password'])
            ->assertRedirect('/admin/dashboard');
        $this->get('/admin/products/create')->assertOk()->assertSee('商品圖片');

        $this->get('/admin/customers')->assertNotFound();
        $this->get('/admin/dashboard')->assertOk()
            ->assertDontSee('href="http://localhost/admin/customers"', false)
            ->assertDontSee('href="http://localhost/admin/addresses"', false)
            ->assertSee('直接建立訂單，客戶資料一起記住');
        $this->get('/admin/addresses')->assertNotFound();
        $this->get('/admin/addresses/create')->assertNotFound();

        $this->post('/admin/products', [
            'name' => '雲端服務',
            'price' => 1200.49,
            'cost' => 345.50,
            'image' => UploadedFile::fake()->image('product.png'),
        ])->assertRedirect('/admin/products');
        $product = DB::table('crm_products')->first();
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertDatabaseHas('crm_products', ['id' => $product->id, 'price' => 1200, 'cost' => 346]);

        $this->post('/admin/products', ['sku' => '02', 'name' => '文旦10斤', 'category' => '文旦', 'price' => 600])
            ->assertRedirect('/admin/products');
        $this->post('/admin/products', ['sku' => '01', 'name' => '花生糖', 'category' => '花生', 'price' => 160])
            ->assertRedirect('/admin/products');
        $peanutId = DB::table('crm_products')->where('name', '花生糖')->value('id');
        $this->get('/admin/products')->assertOk()
            ->assertSee('aria-label="上移 花生糖"', false)
            ->assertSee('aria-label="下移 花生糖"', false)
            ->assertSee('$1,200')
            ->assertDontSee('$1,200.00')
            ->assertSeeInOrder(['雲端服務', '文旦10斤', '花生糖']);
        $this->post('/admin/products/'.$peanutId.'/move', ['direction' => 'up'])
            ->assertRedirect('/admin/products');
        $this->assertSame(
            ['雲端服務', '花生糖', '文旦10斤'],
            DB::table('crm_products')->orderBy('sort_order')->orderBy('id')->pluck('name')->all()
        );
        $this->get('/admin/products')->assertOk()
            ->assertSee('商品順序已自動儲存。')
            ->assertSeeInOrder(['雲端服務', '花生糖', '文旦10斤']);
        $this->get('/admin/orders/create')->assertOk()
            ->assertSeeInOrder(['雲端服務｜$1,200', '花生糖｜$160', '文旦10斤｜$600']);

        $this->get('/admin/orders/create')->assertOk()
            ->assertDontSee('搜尋舊客戶電話')
            ->assertSeeInOrder(['姓名', '市話', '手機電話', '統一編號', '地址', '客戶備註'])
            ->assertDontSee('customer_email')
            ->assertDontSee('Email')
            ->assertSee('id="customer_name"', false)
            ->assertSee('list="order-customer-name-history"', false)
            ->assertSee('list="order-customer-phone-history"', false)
            ->assertSee('list="order-customer-mobile-history"', false)
            ->assertSee('lang="zh-TW" autocomplete="off" autocapitalize="off" spellcheck="false"', false)
            ->assertSee('lang="zh-TW" autocomplete="street-address" autocapitalize="off" spellcheck="false"', false)
            ->assertSee('style="grid-column:1/-1"><label for="customer_address"', false)
            ->assertSee('id="order_date" name="order_date" type="text" value=""', false)
            ->assertSee('placeholder="例如 20260722"', false)
            ->assertSee('data-date-input inputmode="numeric" autocomplete="off"', false)
            ->assertSee('class="btn btn-sm btn-secondary open-date-picker"', false)
            ->assertSee('📅 選日期')
            ->assertSee('class="date-picker-popover" data-picker-for="order_date" hidden', false)
            ->assertSee('class="date-picker-weekdays"', false)
            ->assertSee('data-target="order_date">今天</button>', false)
            ->assertSee('接洽人')
            ->assertSee('value="已付款" selected', false)
            ->assertSee('value="銀行轉帳" selected', false)
            ->assertDontSee('訂單狀態')
            ->assertDontSee('折扣')
            ->assertDontSee('運費')
            ->assertDontSee('稅額')
            ->assertSee('name="items[0][unit_price]" type="number" min="0" step="1"', false)
            ->assertSee('id="items-total">$0</strong>', false)
            ->assertDontSee('$0.00')
            ->assertDontSee('minimumFractionDigits')
            ->assertDontSee('customerIdentityKeys', false)
            ->assertSee('const customer=orderCustomers.find(item=>item[key]===input.value)', false)
            ->assertSee("logActivity('customer_selected'", false)
            ->assertSee("logActivity('customer_entered'", false)
            ->assertSee("contactSelect?.addEventListener('change'", false)
            ->assertSee("'product_selected'", false)
            ->assertSee('id="activity-log-status"', false)
            ->assertSee('尚未有新的選擇');

        $this->post('/admin/orders', [
            'customer_name' => '測試客戶',
            'customer_phone' => '02-1234-5678',
            'customer_mobile' => '0912-345-678',
            'customer_address' => '台北市信義區測試路 1 號',
            'order_date' => '2026-07-20',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 1200.49,
            ]],
        ])->assertRedirect('/admin/orders');
        $customerId = DB::table('crm_customers')->value('id');
        DB::table('crm_customers')->where('id', $customerId)->update(['email' => 'legacy-customer@example.com']);
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerId,
            'name' => '測試客戶',
            'mobile' => '0912-345-678',
            'address' => '台北市信義區測試路 1 號',
        ]);
        $this->assertDatabaseHas('crm_orders', ['customer_id' => $customerId]);
        $firstOrderId = DB::table('crm_orders')->value('id');
        $this->get('/admin/orders/'.$firstOrderId.'/edit')->assertOk()
            ->assertSee('id="order_date" name="order_date" type="text" value="2026-07-20"', false);
        DB::table('crm_orders')->where('id', $firstOrderId)->update([
            'status' => '內部隱藏狀態',
            'discount' => 111,
            'shipping_fee' => 222,
            'tax' => 333,
        ]);
        $this->get('/admin/orders')->assertOk()
            ->assertSee('搜尋客戶、接洽人、市話、手機電話、地址或訂單資料…')
            ->assertSee("input.addEventListener('change',submitSearch)", false)
            ->assertSee("event.key==='Enter'", false)
            ->assertSee("document.addEventListener('pointerdown'", false)
            ->assertSee("!form.contains(event.target)", false)
            ->assertDontSee('內部隱藏狀態')
            ->assertDontSee('折扣')
            ->assertDontSee('運費')
            ->assertDontSee('稅額');
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('內部隱藏狀態');

        DB::table('crm_contacts')->insert([
            'customer_id' => $customerId,
            'name' => '王小明',
            'email' => 'legacy-contact@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactId = DB::table('crm_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => '陳威仁',
            'email' => 'legacy-default@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/orders/create')->assertOk()
            ->assertDontSee('搜尋舊客戶電話')
            ->assertSee('02-1234-5678')
            ->assertSee('0912-345-678')
            ->assertSee('台北市信義區測試路 1 號')
            ->assertSee('測試客戶')
            ->assertSee('陳威仁')
            ->assertSee('王小明')
            ->assertSee('value="'.$contactId.'" selected', false)
            ->assertDontSee('legacy-customer@example.com')
            ->assertDontSee('customer_email')
            ->assertDontSee('Email')
            ->assertDontSee('contactSelect.value=customer.contact_id');

        $this->get('/admin/orders?search='.urlencode('試客'))->assertOk()->assertSee('測試客戶');
        $this->get('/admin/orders?search='.urlencode('1234'))->assertOk()->assertSee('測試客戶');
        $this->get('/admin/orders?search='.urlencode('345-678'))->assertOk()->assertSee('測試客戶');
        $this->get('/admin/orders?search='.urlencode('信義區測試路'))->assertOk()->assertSee('測試客戶');

        $this->get('/admin/contacts')->assertOk()
            ->assertDontSee('Email')
            ->assertDontSee('legacy-contact@example.com');
        $this->get('/admin/contacts/create')->assertOk()
            ->assertDontSee('name="email"', false)
            ->assertDontSee('Email');
        $this->get('/admin/contacts/'.$contactId.'/edit')->assertOk()
            ->assertDontSee('name="email"', false)
            ->assertDontSee('legacy-default@example.com')
            ->assertDontSee('Email');

        $this->post('/admin/orders', [
            'customer_id' => $customerId,
            'customer_name' => '測試客戶（更新）',
            'customer_phone' => '02-1234-5678',
            'customer_mobile' => '0912-345-678',
            'customer_address' => '台北市信義區更新路 2 號',
            'contact_id' => $contactId,
            'order_date' => '20260722',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 1200,
            ]],
        ])->assertRedirect('/admin/orders');
        $this->assertSame(1, DB::table('crm_customers')->count());
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerId,
            'name' => '測試客戶（更新）',
            'address' => '台北市信義區更新路 2 號',
        ]);
        $this->assertDatabaseHas('crm_orders', ['customer_id' => $customerId, 'contact_id' => $contactId]);
        $contactOrder = \App\Models\CrmOrder::latest('id')->first();
        $firstOrderNumber = DB::table('crm_orders')->where('id', $firstOrderId)->value('order_number');
        $this->assertSame('2026-07-22', $contactOrder->order_date->toDateString());
        $this->get('/admin/orders?search='.urlencode('陳威仁'))->assertOk()
            ->assertSee('接洽人')
            ->assertSee('陳威仁')
            ->assertSee($contactOrder->order_number)
            ->assertDontSee($firstOrderNumber);
        $this->get('/admin/products/create')->assertOk()
            ->assertSee('value="包"', false)
            ->assertSee('value="罐"', false);

        $this->assertDatabaseHas('crm_orders', ['subtotal' => 2400, 'total' => 2400, 'customer_id' => $customerId]);
        $this->assertDatabaseHas('crm_order_items', ['product_name' => '雲端服務', 'unit_price' => 1200, 'line_total' => 2400]);

        $this->get('/admin/orders')->assertOk()
            ->assertSee('tbody tr:nth-child(odd)', false)
            ->assertSee('tbody tr:nth-child(even)', false)
            ->assertSee('--row-odd:rgba(5,8,22,.2)', false)
            ->assertSee('--row-even:rgba(65,78,128,.14)', false)
            ->assertSee('--row-hover:rgba(124,92,255,.13)', false)
            ->assertSee('sort=order_number&amp;direction=asc', false)
            ->assertSee('sort=order_date&amp;direction=asc', false)
            ->assertSee('sort=customer.name&amp;direction=asc', false)
            ->assertSee('sort=payment_status&amp;direction=asc', false)
            ->assertSee('sort=total&amp;direction=asc', false);
        $this->get('/admin/orders?sort=total&direction=asc')->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->assertSeeInOrder(['$1,200', '$2,400'])
            ->assertDontSee('$1,200.00')
            ->assertDontSee('$2,400.00');
        $this->get('/admin/orders?sort=order_date&direction=desc')->assertOk()
            ->assertSee('aria-sort="descending"', false)
            ->assertSeeInOrder(['2026-07-22', '2026-07-20']);
        $this->get('/admin/orders?sort=customer.name&direction=asc')->assertOk()
            ->assertSee('aria-sort="ascending"', false);

        foreach (range(1, 24) as $pageOrder) {
            DB::table('crm_orders')->insert([
                'order_number' => 'PAGER-'.str_pad((string) $pageOrder, 2, '0', STR_PAD_LEFT),
                'customer_id' => $customerId,
                'order_date' => '2026-01-01',
            ]);
        }
        $defaultPageResponse = $this->get('/admin/orders')->assertOk()
            ->assertSee('class="per-page-control"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('data-per-page aria-label="每頁顯示筆數"', false)
            ->assertSee('value="20" selected', false)
            ->assertSeeInOrder(['20 筆', '50 筆', '100 筆', '200 筆'])
            ->assertSee("perPage?.addEventListener('change',submitSearch)", false)
            ->assertSee('class="crm-pagination"', false)
            ->assertSee('class="crm-pagination-prev"', false)
            ->assertSee('class="crm-pagination-pages"', false)
            ->assertSee('class="crm-pagination-next"', false)
            ->assertSee('grid-template-columns:minmax(90px,1fr) auto minmax(90px,1fr)', false)
            ->assertSee('background:linear-gradient(90deg,#0d1327,#151d38 50%,#0d1327)', false)
            ->assertSee('background:#f7f8fc', false)
            ->assertSee('background:#1688e8', false)
            ->assertSeeInOrder(['上一頁', '1', '2', '下一頁'])
            ->assertSee('rel="next"', false);
        $this->assertSame(21, substr_count($defaultPageResponse->getContent(), '<tr'));

        $expandedPageResponse = $this->get('/admin/orders?per_page=50')->assertOk()
            ->assertSee('value="50" selected', false)
            ->assertDontSee('class="crm-pagination"', false);
        $this->assertSame(27, substr_count($expandedPageResponse->getContent(), '<tr'));

        $invalidPageSizeResponse = $this->get('/admin/orders?per_page=999')->assertOk()
            ->assertSee('value="20" selected', false)
            ->assertSee('class="crm-pagination"', false);
        $this->assertSame(21, substr_count($invalidPageSizeResponse->getContent(), '<tr'));

        $exportResponse = $this->get('/admin/export/xlsx')
            ->assertOk()
            ->assertHeader('content-disposition');
        $spreadsheet = IOFactory::load($exportResponse->baseResponse->getFile()->getPathname());
        $this->assertNull($spreadsheet->getSheetByName('地址'));
        $customerSheet = $spreadsheet->getSheetByName('客戶');
        $this->assertSame('A5', $customerSheet->getFreezePane());
        $this->assertSame(Border::BORDER_THIN, $customerSheet->getStyle('A4')->getBorders()->getTop()->getBorderStyle());
        $this->assertGreaterThanOrEqual(10, $customerSheet->getColumnDimension('A')->getWidth());
        $this->assertSame('市話', $customerSheet->getCell('C4')->getValue());
        $this->assertSame('手機電話', $customerSheet->getCell('D4')->getValue());
        $this->assertSame('地址', $customerSheet->getCell('E4')->getValue());
        $customerHeaders = $customerSheet->rangeToArray('A4:J4')[0];
        $contactSheet = $spreadsheet->getSheetByName('接洽人');
        $contactHeaders = $contactSheet->rangeToArray('A4:H4')[0];
        $this->assertNotContains('Email', $customerHeaders);
        $this->assertNotContains('Email', $contactHeaders);
        $this->assertNotContains('legacy-customer@example.com', $customerSheet->rangeToArray('A5:J5')[0]);
        $this->assertNotContains('legacy-contact@example.com', $contactSheet->rangeToArray('A5:H6')[0]);
        $this->assertNotContains('legacy-default@example.com', $contactSheet->rangeToArray('A5:H6')[1]);
        $productSheet = $spreadsheet->getSheetByName('商品');
        $this->assertSame(1200, $productSheet->getCell('D5')->getValue());
        $this->assertSame(346, $productSheet->getCell('E5')->getValue());
        $this->assertSame('#,##0', $productSheet->getStyle('D5')->getNumberFormat()->getFormatCode());
        $orderSheet = $spreadsheet->getSheetByName('訂單');
        $orderHeaders = $orderSheet->rangeToArray('A4:I4')[0];
        $this->assertSame(['訂單編號', '日期', '客戶', '接洽人', '付款狀態', '付款方式', '小計', '總額', '備註'], $orderHeaders);
        $this->assertSame(2400, $orderSheet->getCell('G5')->getValue());
        $this->assertSame(2400, $orderSheet->getCell('H5')->getValue());
        $this->assertSame('#,##0', $orderSheet->getStyle('H5')->getNumberFormat()->getFormatCode());
        $orderItemSheet = $spreadsheet->getSheetByName('訂單明細');
        $this->assertSame(1200, $orderItemSheet->getCell('D5')->getValue());
        $this->assertSame(2400, $orderItemSheet->getCell('E5')->getValue());
        $this->assertSame('#,##0', $orderItemSheet->getStyle('E5')->getNumberFormat()->getFormatCode());
        $this->assertNotContains('狀態', $orderHeaders);
        $this->assertNotContains('折扣', $orderHeaders);
        $this->assertNotContains('運費', $orderHeaders);
        $this->assertNotContains('稅額', $orderHeaders);
        $this->assertNotContains('內部隱藏狀態', $orderSheet->rangeToArray('A5:I6')[0]);
    }

    public function test_order_activity_is_logged_before_validation_and_through_save_and_delete(): void
    {
        $logPath = storage_path('framework/testing/crm-order-activity.log');
        @unlink($logPath);
        config()->set('logging.channels.crm_order_activity', [
            'driver' => 'single',
            'path' => $logPath,
            'level' => 'info',
        ]);
        Log::forgetChannel('crm_order_activity');

        $this->post('/admin/login', ['username' => 'test-admin', 'password' => 'test-password'])
            ->assertRedirect('/admin/dashboard');

        $customerId = DB::table('crm_customers')->insertGetId([
            'name' => '紀錄測試客戶',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactId = DB::table('crm_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => '紀錄測試接洽人',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = DB::table('crm_products')->insertGetId([
            'name' => '紀錄測試商品',
            'price' => 800,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $draftId = (string) Str::uuid();

        $this->postJson('/admin/orders/activity-log', [
            'event' => 'product_selected',
            'draft_id' => $draftId,
            'client_selected_at' => now()->toIso8601String(),
            'mode' => 'create',
            'customer_id' => $customerId,
            'contact_id' => $contactId,
            'product_id' => $productId,
            'row_index' => 0,
            'quantity' => 2,
            'unit_price' => 800,
        ])->assertOk()->assertJson(['logged' => true]);

        $this->post('/admin/orders', [
            'activity_draft_id' => $draftId,
            'customer_id' => $customerId,
            'customer_name' => '紀錄測試客戶',
            'contact_id' => $contactId,
            'items' => [],
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('crm_orders', 0);

        $this->post('/admin/orders', [
            'activity_draft_id' => $draftId,
            'customer_id' => $customerId,
            'customer_name' => '紀錄測試客戶',
            'contact_id' => $contactId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'unit_price' => 800,
            ]],
        ])->assertRedirect('/admin/orders');

        $orderId = (int) DB::table('crm_orders')->value('id');
        $this->delete('/admin/orders/'.$orderId)->assertRedirect();

        $log = file_get_contents($logPath);
        $this->assertStringContainsString('crm_order_product_selected', $log);
        $this->assertStringContainsString('crm_order_submit_attempted', $log);
        $this->assertStringContainsString('crm_order_saved', $log);
        $this->assertStringContainsString('crm_order_deleted', $log);
        $this->assertStringContainsString('紀錄測試客戶', $log);
        $this->assertStringContainsString('紀錄測試接洽人', $log);
        $this->assertStringContainsString('紀錄測試商品', $log);
        $this->assertStringContainsString($draftId, $log);
    }

    public function test_matching_phone_overwrites_the_existing_customer_regardless_of_name(): void
    {
        $this->post('/admin/login', ['username' => 'test-admin', 'password' => 'test-password'])
            ->assertRedirect('/admin/dashboard');

        $productId = DB::table('crm_products')->insertGetId([
            'name' => '共用電話測試商品',
            'price' => 100,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerAId = DB::table('crm_customers')->insertGetId([
            'name' => '客戶 A',
            'phone' => '123',
            'address' => 'A 地址',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/admin/orders', [
            'order_number' => 'CUSTOMER-SAME-NAME',
            'customer_name' => '客戶 A',
            'customer_phone' => '123',
            'customer_address' => '同名覆蓋地址',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])->assertRedirect('/admin/orders');

        $this->assertSame(1, DB::table('crm_customers')->count());
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerAId,
            'name' => '客戶 A',
            'phone' => '123',
            'address' => '同名覆蓋地址',
        ]);
        $this->assertDatabaseHas('crm_orders', [
            'order_number' => 'CUSTOMER-SAME-NAME',
            'customer_id' => $customerAId,
        ]);

        $this->post('/admin/orders', [
            'order_number' => 'CUSTOMER-DIFFERENT-NAME',
            'customer_name' => '客戶 B',
            'customer_phone' => '123',
            'customer_address' => '不同名覆蓋地址',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])->assertRedirect('/admin/orders');

        $this->assertSame(1, DB::table('crm_customers')->count());
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerAId,
            'name' => '客戶 B',
            'phone' => '123',
            'address' => '不同名覆蓋地址',
        ]);
        $this->assertDatabaseHas('crm_orders', [
            'order_number' => 'CUSTOMER-DIFFERENT-NAME',
            'customer_id' => $customerAId,
        ]);

        $this->post('/admin/orders', [
            'order_number' => 'CUSTOMER-MOBILE-CROSS-MATCH',
            'customer_name' => '客戶 C',
            'customer_mobile' => '123',
            'customer_address' => '手機交叉比對地址',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])->assertRedirect('/admin/orders');

        $this->assertSame(1, DB::table('crm_customers')->count());
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerAId,
            'name' => '客戶 C',
            'phone' => null,
            'mobile' => '123',
            'address' => '手機交叉比對地址',
        ]);
        $this->assertDatabaseHas('crm_orders', [
            'order_number' => 'CUSTOMER-MOBILE-CROSS-MATCH',
            'customer_id' => $customerAId,
        ]);
    }

    public function test_orders_can_be_queried_and_exported_by_year_and_contact(): void
    {
        $this->post('/admin/login', ['username' => 'test-admin', 'password' => 'test-password'])
            ->assertRedirect('/admin/dashboard');

        $customerId = DB::table('crm_customers')->insertGetId([
            'name' => '匯出測試客戶',
            'phone' => '02-1234-5678',
            'mobile' => '0912-345-678',
            'address' => '台北市測試路 1 號',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactA = DB::table('crm_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => '接洽人甲',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactB = DB::table('crm_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => '接洽人乙',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_contacts')->insert([
            'customer_id' => $customerId,
            'name' => '接洽人無訂單',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = DB::table('crm_products')->insertGetId([
            'name' => '匯出測試商品',
            'price' => 500,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['EXPORT-2025-A', '2025-06-01', $contactA],
            ['EXPORT-2026-A', '2026-06-01', $contactA],
            ['EXPORT-2026-B', '2026-07-01', $contactB],
        ] as [$number, $date, $contactId]) {
            $orderId = DB::table('crm_orders')->insertGetId([
                'order_number' => $number,
                'customer_id' => $customerId,
                'contact_id' => $contactId,
                'order_date' => $date,
                'subtotal' => 500,
                'total' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('crm_order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $productId,
                'product_name' => '匯出測試商品',
                'quantity' => 1,
                'unit_price' => 500,
                'line_total' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->get('/admin/export')->assertOk()
            ->assertSee('目前客戶總數')
            ->assertSee('目前訂單總數')
            ->assertSee('<details class="panel contact-counts">', false)
            ->assertDontSee('<details class="panel contact-counts" open>', false)
            ->assertSee('每位接洽人訂單數（點擊展開）')
            ->assertSee('接洽人甲')
            ->assertSee('2 張')
            ->assertSee('接洽人乙')
            ->assertSee('1 張')
            ->assertSee('接洽人無訂單')
            ->assertSee('0 張')
            ->assertSee('依照年份匯出')
            ->assertSee('依照接洽人匯出')
            ->assertSee('依照接洽人全部匯出')
            ->assertSee('接洽人分頁匯出')
            ->assertSee('全部接洽人分頁匯出')
            ->assertSee('2026 年')
            ->assertSee('接洽人甲');
        $this->get('/admin/export?year=2026&contact_id='.$contactA)->assertOk()
            ->assertSee('EXPORT-2026-A')
            ->assertDontSee('EXPORT-2025-A')
            ->assertDontSee('EXPORT-2026-B')
            ->assertSee('mode=year_contact', false)
            ->assertSee('mode=contact_all', false)
            ->assertSee('mode=contact_sheets', false);

        $yearResponse = $this->get('/admin/export/xlsx?mode=year&year=2026')->assertOk();
        $yearWorkbook = IOFactory::load($yearResponse->baseResponse->getFile()->getPathname());
        $this->assertSame('篩選條件：2026 年全部訂單', explode('｜匯出時間：', $yearWorkbook->getSheetByName('訂單')->getCell('A2')->getValue())[0]);
        $this->assertSame(['EXPORT-2026-A', 'EXPORT-2026-B'], array_column($yearWorkbook->getSheetByName('訂單')->rangeToArray('A5:A6'), 0));

        $contactYearResponse = $this->get('/admin/export/xlsx?mode=year_contact&year=2026&contact_id='.$contactA)->assertOk();
        $contactYearWorkbook = IOFactory::load($contactYearResponse->baseResponse->getFile()->getPathname());
        $this->assertSame('EXPORT-2026-A', $contactYearWorkbook->getSheetByName('訂單')->getCell('A5')->getValue());
        $this->assertNull($contactYearWorkbook->getSheetByName('訂單')->getCell('A6')->getValue());

        $contactAllResponse = $this->get('/admin/export/xlsx?mode=contact_all&contact_id='.$contactA)->assertOk();
        $contactAllWorkbook = IOFactory::load($contactAllResponse->baseResponse->getFile()->getPathname());
        $this->assertSame(['EXPORT-2025-A', 'EXPORT-2026-A'], array_column($contactAllWorkbook->getSheetByName('訂單')->rangeToArray('A5:A6'), 0));
        $this->assertSame('接洽人甲', $contactAllWorkbook->getSheetByName('接洽人')->getCell('B5')->getValue());
        $this->assertNull($contactAllWorkbook->getSheetByName('接洽人')->getCell('B6')->getValue());

        $contactSheetsResponse = $this->get('/admin/export/xlsx?mode=contact_sheets')->assertOk();
        $contactSheetsWorkbook = IOFactory::load($contactSheetsResponse->baseResponse->getFile()->getPathname());
        $contactSheetNames = $contactSheetsWorkbook->getSheetNames();
        sort($contactSheetNames);
        $expectedContactSheetNames = ['接洽人甲', '接洽人乙', '接洽人無訂單'];
        sort($expectedContactSheetNames);
        $this->assertSame($expectedContactSheetNames, $contactSheetNames);
        $this->assertSame(['日期', '人名', '電話', '地址', '品項'], $contactSheetsWorkbook->getSheetByName('接洽人甲')->rangeToArray('A4:E4')[0]);
        $this->assertSame(['2026-07-01'], array_column($contactSheetsWorkbook->getSheetByName('接洽人乙')->rangeToArray('A5:A5'), 0));
        $this->assertSame(['2025-06-01', '2026-06-01'], array_column($contactSheetsWorkbook->getSheetByName('接洽人甲')->rangeToArray('A5:A6'), 0));
        $this->assertNull($contactSheetsWorkbook->getSheetByName('接洽人無訂單')->getCell('A5')->getValue());

        $selectedContactSheetsResponse = $this->get('/admin/export/xlsx?mode=contact_sheets&contact_id='.$contactA)->assertOk();
        $selectedContactSheetsWorkbook = IOFactory::load($selectedContactSheetsResponse->baseResponse->getFile()->getPathname());
        $this->assertSame(['接洽人甲'], $selectedContactSheetsWorkbook->getSheetNames());
        $this->assertSame('匯出測試客戶', $selectedContactSheetsWorkbook->getSheetByName('接洽人甲')->getCell('B5')->getValue());
        $this->assertSame("02-1234-5678\n0912-345-678", $selectedContactSheetsWorkbook->getSheetByName('接洽人甲')->getCell('C5')->getValue());
        $this->assertSame('台北市測試路 1 號', $selectedContactSheetsWorkbook->getSheetByName('接洽人甲')->getCell('D5')->getValue());
        $this->assertSame('匯出測試商品 × 1', $selectedContactSheetsWorkbook->getSheetByName('接洽人甲')->getCell('E5')->getValue());

        $this->get('/admin/export/xlsx?mode=year')->assertSessionHasErrors('year');
        $this->get('/admin/export/xlsx?mode=contact_all')->assertSessionHasErrors('contact_id');
    }
}
