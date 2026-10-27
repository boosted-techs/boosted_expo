<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exception_cases', fn (Blueprint $t) => $t->foreignId('resolution_workflow_id')->nullable()->constrained('finance_workflows'));
        Schema::create('finance_trade_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->foreignId('case_id')->constrained('stock_exception_cases');
            $t->foreignId('commercial_invoice_id')->nullable()->constrained();
            $t->foreignId('supplier_bill_id')->nullable()->constrained('finance_supplier_bills');
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->decimal('tax_amount', 20, 2);
            $t->decimal('applied_amount', 20, 2);
            $t->decimal('excess_amount', 20, 2);
            $t->decimal('excess_base', 20, 2);
            $t->date('due_on')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'commercial_invoice_id']);
            $t->index(['business_id', 'supplier_bill_id']);
        });
        Schema::create('finance_trade_credit_uses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('trade_credit_id')->constrained('finance_trade_credits');
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->string('kind', 30);
            $t->foreignId('commercial_invoice_id')->nullable()->constrained();
            $t->foreignId('supplier_bill_id')->nullable()->constrained('finance_supplier_bills');
            $t->decimal('amount', 20, 2);
            $t->decimal('control_base', 20, 2);
            $t->timestamps();
            $t->index(['business_id', 'trade_credit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_exception_cases', fn (Blueprint $t) => $t->dropConstrainedForeignId('resolution_workflow_id'));
        Schema::dropIfExists('finance_trade_credit_uses');
        Schema::dropIfExists('finance_trade_credits');
    }
};
