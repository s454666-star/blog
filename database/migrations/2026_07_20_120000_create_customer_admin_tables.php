<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name');
            $table->string('tax_id', 20)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->string('name');
            $table->string('title', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_contact', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('recipient', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('county', 50)->nullable();
            $table->string('district', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->boolean('is_default')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 80)->nullable()->unique();
            $table->string('name');
            $table->string('category', 100)->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('cost', 14, 2)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('stock_quantity', 14, 2)->nullable();
            $table->decimal('tax_rate', 6, 2)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('image_path')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 60)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('crm_addresses')->nullOnDelete();
            $table->date('order_date')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('payment_status', 30)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->nullable();
            $table->decimal('shipping_fee', 14, 2)->nullable();
            $table->decimal('tax', 14, 2)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('crm_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('crm_products')->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_order_items');
        Schema::dropIfExists('crm_orders');
        Schema::dropIfExists('crm_products');
        Schema::dropIfExists('crm_addresses');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_customers');
    }
};
