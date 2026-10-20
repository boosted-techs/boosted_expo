<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('customer_id');
            $t->string('reference', 48);
            $t->string('status', 20)->default('open');
            $t->date('required_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','customer_id'])->references(['business_id','id'])
                ->on('customers')->restrictOnDelete();
        });
        Schema::create('sales_request_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sales_request_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity',14,3);
            $t->timestamps();
            $t->unique(['sales_request_id','product_id']);
            $t->foreign(['business_id','sales_request_id'])->references(['business_id','id'])
                ->on('sales_requests')->cascadeOnDelete();
            $t->foreign(['business_id','product_id'])->references(['business_id','id'])
                ->on('products')->restrictOnDelete();
        });
        Schema::create('sales_quotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sales_request_id');
            $t->string('reference',65);
            $t->unsignedInteger('revision');
            $t->string('status', 20)->default('draft');
            $t->string('currency',3);
            $t->string('incoterm',10)->nullable();
            $t->date('valid_until')->nullable();
            $t->decimal('discount_percent',5,2)->default(0);
            $t->decimal('tax_percent',5,2)->default(0);
            $t->decimal('subtotal',20,2);
            $t->decimal('discount_amount',20,2);
            $t->decimal('tax_amount',20,2);
            $t->decimal('grand_total',20,2);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['sales_request_id','revision']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','sales_request_id'])->references(['business_id','id'])
                ->on('sales_requests')->restrictOnDelete();
        });
        Schema::create('sales_quotation_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sales_quotation_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity',14,3);
            $t->decimal('unit_price',18,4);
            $t->decimal('line_total',20,2);
            $t->timestamps();
            $t->unique(['sales_quotation_id','product_id']);
            $t->foreign(['business_id','sales_quotation_id'])->references(['business_id','id'])
                ->on('sales_quotations')->cascadeOnDelete();
            $t->foreign(['business_id','product_id'])->references(['business_id','id'])
                ->on('products')->restrictOnDelete();
        });
        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',48);
            $t->unsignedBigInteger('sales_request_id');
            $t->unsignedBigInteger('sales_quotation_id');
            $t->unsignedBigInteger('customer_id');
            $t->string('status',20)->default('confirmed');
            $t->string('currency',3);
            $t->string('incoterm',10)->nullable();
            $t->decimal('discount_percent',5,2)->default(0);
            $t->decimal('tax_percent',5,2)->default(0);
            $t->decimal('subtotal',20,2);
            $t->decimal('discount_amount',20,2);
            $t->decimal('tax_amount',20,2);
            $t->decimal('grand_total',20,2);
            $t->string('buyer_confirmation',160);
            $t->text('notes')->nullable();
            $t->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('confirmed_at');
            $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['sales_quotation_id']);
            $t->unique(['sales_request_id']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','customer_id'])->references(['business_id','id'])
                ->on('customers')->restrictOnDelete();
            $t->foreign(['business_id','sales_request_id'])->references(['business_id','id'])
                ->on('sales_requests')->restrictOnDelete();
            $t->foreign(['business_id','sales_quotation_id'])->references(['business_id','id'])
                ->on('sales_quotations')->restrictOnDelete();
        });
        Schema::create('sales_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sales_order_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity',14,3);
            $t->decimal('unit_price',18,4);
            $t->decimal('line_total',20,2);
            $t->timestamps();
            $t->unique(['sales_order_id','product_id']);
            $t->foreign(['business_id','sales_order_id'])->references(['business_id','id'])
                ->on('sales_orders')->cascadeOnDelete();
            $t->foreign(['business_id','product_id'])->references(['business_id','id'])
                ->on('products')->restrictOnDelete();
        });
        Schema::table('export_shipments', function (Blueprint $t) {
            $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->index(['business_id','sales_order_id']);
            $t->foreign(['business_id','sales_order_id'])
                ->references(['business_id','id'])->on('sales_orders')->restrictOnDelete();
        });
        Schema::create('commercial_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('sales_order_id');
            $t->unsignedBigInteger('customer_id');
            $t->string('reference',65);
            $t->string('currency',3);
            $t->decimal('subtotal',20,2);
            $t->decimal('discount_amount',20,2);
            $t->decimal('tax_amount',20,2);
            $t->decimal('freight_amount',20,2)->default(0);
            $t->decimal('insurance_amount',20,2)->default(0);
            $t->decimal('grand_total',20,2);
            $t->string('posting_status',20)->default('not_posted');
            $t->json('data_snapshot');
            $t->longText('html_snapshot');
            $t->string('sha256',64);
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->unique(['shipment_id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','shipment_id'])->references(['business_id','id'])
                ->on('export_shipments')->restrictOnDelete();
            $t->foreign(['business_id','sales_order_id'])->references(['business_id','id'])
                ->on('sales_orders')->restrictOnDelete();
            $t->foreign(['business_id','customer_id'])->references(['business_id','id'])
                ->on('customers')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('commercial_invoices');
        Schema::table('export_shipments', function (Blueprint $t) {
            $t->dropForeign(['business_id','sales_order_id']);
            $t->dropIndex(['business_id','sales_order_id']);
            $t->dropColumn('sales_order_id');
        });
        foreach ([
            'sales_order_lines','sales_orders','sales_quotation_lines',
            'sales_quotations','sales_request_items','sales_requests'
        ] as $table) Schema::dropIfExists($table);
    }
};
