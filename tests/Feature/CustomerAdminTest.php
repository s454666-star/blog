<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('直接建立訂單，客戶資料一起記住');

        $this->post('/admin/products', [
            'name' => '雲端服務',
            'price' => 1200,
            'image' => UploadedFile::fake()->image('product.png'),
        ])->assertRedirect('/admin/products');
        $product = DB::table('crm_products')->first();
        Storage::disk('public')->assertExists($product->image_path);

        $this->get('/admin/orders/create')->assertOk()
            ->assertSee('搜尋舊客戶電話')
            ->assertSee('輸入部分號碼，例如 0909')
            ->assertSeeInOrder(['客戶姓名', '市話', '手機電話', '統一編號', 'Email', '地址', '客戶備註'])
            ->assertSee('接洽人')
            ->assertDontSee('訂單狀態')
            ->assertDontSee('折扣')
            ->assertDontSee('運費')
            ->assertDontSee('稅額');

        $this->post('/admin/orders', [
            'customer_name' => '測試客戶',
            'customer_phone' => '02-1234-5678',
            'customer_mobile' => '0912-345-678',
            'customer_address' => '台北市信義區測試路 1 號',
            'customer_email' => 'customer@example.com',
            'order_date' => '2026-07-20',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 1200,
            ]],
        ])->assertRedirect('/admin/orders');
        $customerId = DB::table('crm_customers')->value('id');
        $this->assertDatabaseHas('crm_customers', [
            'id' => $customerId,
            'name' => '測試客戶',
            'mobile' => '0912-345-678',
            'address' => '台北市信義區測試路 1 號',
        ]);
        $this->assertDatabaseHas('crm_orders', ['customer_id' => $customerId]);
        $firstOrderId = DB::table('crm_orders')->value('id');
        DB::table('crm_orders')->where('id', $firstOrderId)->update([
            'status' => '內部隱藏狀態',
            'discount' => 111,
            'shipping_fee' => 222,
            'tax' => 333,
        ]);
        $this->get('/admin/orders')->assertOk()
            ->assertDontSee('內部隱藏狀態')
            ->assertDontSee('折扣')
            ->assertDontSee('運費')
            ->assertDontSee('稅額');
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('內部隱藏狀態');

        DB::table('crm_contacts')->insert([
            'customer_id' => $customerId,
            'name' => '王小明',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactId = DB::table('crm_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => '陳威仁',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/orders/create')->assertOk()
            ->assertSee('搜尋舊客戶電話')
            ->assertSee('02-1234-5678')
            ->assertSee('0912-345-678')
            ->assertSee('台北市信義區測試路 1 號')
            ->assertSee('測試客戶')
            ->assertSee('陳威仁')
            ->assertSee('王小明')
            ->assertSee('value="'.$contactId.'" selected', false)
            ->assertDontSee('contactSelect.value=customer.contact_id');

        $this->post('/admin/orders', [
            'customer_id' => $customerId,
            'customer_name' => '測試客戶（更新）',
            'customer_phone' => '02-1234-5678',
            'customer_mobile' => '0912-345-678',
            'customer_address' => '台北市信義區更新路 2 號',
            'contact_id' => $contactId,
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
        $this->get('/admin/products/create')->assertOk()
            ->assertSee('value="包"', false)
            ->assertSee('value="罐"', false);

        $this->assertDatabaseHas('crm_orders', ['subtotal' => 2400, 'total' => 2400, 'customer_id' => $customerId]);
        $this->assertDatabaseHas('crm_order_items', ['product_name' => '雲端服務', 'line_total' => 2400]);

        $exportResponse = $this->get('/admin/export/xlsx')
            ->assertOk()
            ->assertHeader('content-disposition');
        $spreadsheet = IOFactory::load($exportResponse->baseResponse->getFile()->getPathname());
        $customerSheet = $spreadsheet->getSheetByName('客戶');
        $this->assertSame('A5', $customerSheet->getFreezePane());
        $this->assertSame(Border::BORDER_THIN, $customerSheet->getStyle('A4')->getBorders()->getTop()->getBorderStyle());
        $this->assertGreaterThanOrEqual(10, $customerSheet->getColumnDimension('A')->getWidth());
        $this->assertSame('市話', $customerSheet->getCell('C4')->getValue());
        $this->assertSame('手機電話', $customerSheet->getCell('D4')->getValue());
        $this->assertSame('地址', $customerSheet->getCell('E4')->getValue());
        $orderHeaders = $spreadsheet->getSheetByName('訂單')->rangeToArray('A4:I4')[0];
        $this->assertSame(['訂單編號', '日期', '客戶', '接洽人', '付款狀態', '付款方式', '小計', '總額', '備註'], $orderHeaders);
        $this->assertNotContains('狀態', $orderHeaders);
        $this->assertNotContains('折扣', $orderHeaders);
        $this->assertNotContains('運費', $orderHeaders);
        $this->assertNotContains('稅額', $orderHeaders);
        $this->assertNotContains('內部隱藏狀態', $spreadsheet->getSheetByName('訂單')->rangeToArray('A5:I6')[0]);
    }
}
