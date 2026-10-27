<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->index(['business_id', 'supplier_bill_id', 'status'], 'payments_supplier_document_status');
            $table->index(['business_id', 'commercial_invoice_id', 'status'], 'payments_customer_document_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropIndex('payments_supplier_document_status');
            $table->dropIndex('payments_customer_document_status');
        });
    }
};
