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

        $this->post('/admin/customers', [
            'name' => '測試客戶',
            'phone' => '02-1234-5678',
            'mobile' => '0912-345-678',
            'address' => '台北市信義區測試路 1 號',
            'status' => '合作中',
        ])->assertRedirect('/admin/customers');
        $customerId = DB::table('crm_customers')->value('id');
        $this->get("/admin/customers/{$customerId}/edit")->assertOk()->assertSee('測試客戶');
        $this->put("/admin/customers/{$customerId}", [
            'name' => '測試客戶有限公司',
            'status' => '合作中',
        ])->assertRedirect('/admin/customers');
        $this->assertDatabaseHas('crm_customers', ['id' => $customerId, 'name' => '測試客戶有限公司']);
        $this->get('/admin/customers/create')->assertOk()
            ->assertSeeInOrder(['客戶編號', '客戶名稱', '市話', '手機電話', '地址', '統一編號'])
            ->assertSee('phone-history')
            ->assertSee('02-1234-5678')
            ->assertSee('mobile-history')
            ->assertSee('0912-345-678');

        $this->post('/admin/products', [
            'name' => '雲端服務',
            'price' => 1200,
            'image' => UploadedFile::fake()->image('product.png'),
        ])->assertRedirect('/admin/products');
        $product = DB::table('crm_products')->first();
        Storage::disk('public')->assertExists($product->image_path);

        $this->post('/admin/orders', [
            'customer_id' => $customerId,
            'order_date' => '2026-07-20',
            'status' => '已確認',
            'discount' => 100,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 1200,
            ]],
        ])->assertRedirect('/admin/orders');
        $this->get('/admin/orders/create')->assertOk()
            ->assertSee('用電話快速帶入客戶')
            ->assertSee('02-1234-5678')
            ->assertSee('0912-345-678')
            ->assertSee('台北市信義區測試路 1 號')
            ->assertSee('測試客戶有限公司');
        $this->get('/admin/products/create')->assertOk()
            ->assertSee('value="包"', false)
            ->assertSee('value="罐"', false);

        $this->assertDatabaseHas('crm_orders', ['subtotal' => 2400, 'total' => 2300]);
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
    }
}
