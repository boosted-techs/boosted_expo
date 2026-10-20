<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('customer_code', 50); $t->string('name', 160);
            $t->string('country_code', 2); $t->string('city', 100)->nullable();
            $t->string('currency', 3)->default('USD'); $t->string('email')->nullable();
            $t->string('phone', 50)->nullable(); $t->text('notes')->nullable();
            $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['business_id','customer_code']); $t->unique(['business_id','id']);
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('supplier_code', 50); $t->string('name', 160);
            $t->string('kind', 20)->default('company');
            $t->string('market_location', 150)->nullable(); $t->string('email')->nullable();
            $t->string('phone', 50)->nullable(); $t->text('notes')->nullable();
            $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['business_id','supplier_code']); $t->unique(['business_id','id']);
        });
        Schema::create('supply_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40); $t->unsignedBigInteger('product_id');
            $t->decimal('quantity', 14, 3); $t->string('currency', 3);
            $t->date('needed_on')->nullable(); $t->dateTime('response_deadline');
            $t->string('status', 22)->default('draft'); $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['business_id','reference']); $t->unique(['business_id','id']);
            $t->foreign(['business_id','product_id'])->references(['business_id','id'])->on('products')->restrictOnDelete();
            $t->index(['business_id','status']);
        });
        Schema::create('supplier_request_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('supply_request_id'); $t->unsignedBigInteger('supplier_id');
            $t->string('token_hash', 64)->unique(); $t->dateTime('expires_at');
            $t->timestamp('sent_at')->nullable(); $t->timestamps();
            $t->unique(['supply_request_id','supplier_id']);
            $t->foreign(['business_id','supply_request_id'])->references(['business_id','id'])->on('supply_requests')->cascadeOnDelete();
            $t->foreign(['business_id','supplier_id'])->references(['business_id','id'])->on('suppliers')->restrictOnDelete();
        });
        Schema::create('supplier_quotes', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('supply_request_id'); $t->unsignedBigInteger('supplier_id');
            $t->decimal('quantity',14,3); $t->decimal('unit_price',18,4);
            $t->string('currency',3); $t->date('delivery_on')->nullable();
            $t->text('notes')->nullable(); $t->unsignedInteger('revision')->default(1);
            $t->string('status',20)->default('submitted'); $t->timestamp('submitted_at'); $t->timestamps();
            $t->unique(['supply_request_id','supplier_id']); $t->unique(['business_id','id']);
            $t->foreign(['business_id','supply_request_id'])->references(['business_id','id'])->on('supply_requests')->restrictOnDelete();
            $t->foreign(['business_id','supplier_id'])->references(['business_id','id'])->on('suppliers')->restrictOnDelete();
        });
        Schema::create('supplier_quote_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('supplier_quote_id'); $t->unsignedInteger('revision');
            $t->decimal('quantity',14,3); $t->decimal('unit_price',18,4);
            $t->string('currency',3); $t->date('delivery_on')->nullable();
            $t->text('notes')->nullable(); $t->string('submitted_via',20); $t->timestamp('created_at');
            $t->unique(['supplier_quote_id','revision']);
            $t->foreign(['business_id','supplier_quote_id'])->references(['business_id','id'])->on('supplier_quotes')->cascadeOnDelete();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',40); $t->string('kind',20);
            $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('supplier_quote_id')->nullable();
            $t->string('vendor_name',160)->nullable(); $t->string('vendor_phone',50)->nullable();
            $t->string('market_location',150)->nullable(); $t->date('purchased_on');
            $t->decimal('quantity',14,3); $t->decimal('unit_price',18,4);
            $t->decimal('estimated_total',20,2); $t->string('currency',3);
            $t->string('status',24)->default('ordered'); $t->text('notes')->nullable();
            $t->uuid('idempotency_key'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['business_id','reference']); $t->unique(['business_id','id']);
            $t->unique(['business_id','idempotency_key']); $t->unique(['supplier_quote_id']);
            $t->foreign(['business_id','product_id'])->references(['business_id','id'])->on('products')->restrictOnDelete();
            $t->foreign(['business_id','supplier_id'])->references(['business_id','id'])->on('suppliers')->restrictOnDelete();
            $t->foreign(['business_id','supplier_quote_id'])->references(['business_id','id'])->on('supplier_quotes')->restrictOnDelete();
            $t->index(['business_id','status']);
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',40); $t->unsignedBigInteger('purchase_order_id');
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->decimal('received_quantity',14,3); $t->decimal('accepted_quantity',14,3);
            $t->decimal('rejected_quantity',14,3); $t->decimal('gross_weight_kg',14,3)->nullable();
            $t->decimal('tare_weight_kg',14,3)->nullable(); $t->string('farmer_code',120)->nullable();
            $t->text('quality_notes')->nullable(); $t->date('received_on');
            $t->foreignId('posted_by')->constrained('users')->restrictOnDelete(); $t->uuid('idempotency_key');
            $t->timestamps(); $t->unique(['business_id','reference']); $t->unique(['business_id','id']);
            $t->unique(['business_id','idempotency_key']);
            $t->foreign(['business_id','purchase_order_id'])->references(['business_id','id'])->on('purchase_orders')->restrictOnDelete();
            $t->foreign(['business_id','batch_id'])->references(['business_id','id'])->on('stock_batches')->restrictOnDelete();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('batch_id'); $t->string('movement_type',32);
            $t->decimal('quantity',14,3); $t->string('unit',12);
            $t->string('source_type',40); $t->unsignedBigInteger('source_id');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['business_id','source_type','source_id','movement_type']);
            $t->foreign(['business_id','batch_id'])->references(['business_id','id'])->on('stock_batches')->restrictOnDelete();
            $t->index(['business_id','batch_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements','goods_receipts','purchase_orders','supplier_quote_revisions',
            'supplier_quotes','supplier_request_links','supply_requests','suppliers','customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};