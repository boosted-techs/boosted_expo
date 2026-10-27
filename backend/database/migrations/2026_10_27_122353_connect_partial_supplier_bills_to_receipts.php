<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_supplier_bills', function (Blueprint $t) {
            $t->dropUnique(['purchase_order_id']);
            $t->json('receipt_snapshot')->nullable();
            $t->index(['business_id', 'purchase_order_id']);
        });
        Schema::create('finance_bill_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('supplier_bill_id')->constrained('finance_supplier_bills');
            $t->foreignId('goods_receipt_id')->constrained();
            $t->decimal('quantity', 18, 3);
            $t->decimal('base_cost', 20, 2)->nullable();
            $t->timestamps();
            $t->unique(['supplier_bill_id', 'goods_receipt_id']);
            $t->index(['business_id', 'goods_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_bill_receipts');
        Schema::table('finance_supplier_bills', function (Blueprint $t) {
            $t->dropIndex(['business_id', 'purchase_order_id']);
            $t->dropColumn('receipt_snapshot');
        });
    }
};
