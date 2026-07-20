<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'status' => '合作中',
        ])->assertRedirect('/admin/customers');
        $customerId = DB::table('crm_customers')->value('id');
        $this->get("/admin/customers/{$customerId}/edit")->assertOk()->assertSee('測試客戶');
        $this->put("/admin/customers/{$customerId}", [
            'name' => '測試客戶有限公司',
            'status' => '合作中',
        ])->assertRedirect('/admin/customers');
        $this->assertDatabaseHas('crm_customers', ['id' => $customerId, 'name' => '測試客戶有限公司']);
        DB::table('crm_customers')->where('id', $customerId)->update(['phone' => '02-1234-5678']);
        $this->get('/admin/customers/create')->assertOk()
            ->assertSee('phone-history')
            ->assertSee('02-1234-5678');

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
            ->assertSee('測試客戶有限公司');

        $this->assertDatabaseHas('crm_orders', ['subtotal' => 2400, 'total' => 2300]);
        $this->assertDatabaseHas('crm_order_items', ['product_name' => '雲端服務', 'line_total' => 2400]);

        $this->get('/admin/export/xlsx')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
