<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12); $t->string('name', 100);
            $t->string('type', 18); $t->string('system_key', 36);
            $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['business_id','code']); $t->unique(['business_id','system_key']);
            $t->unique(['business_id','id']);
        });
        Schema::create('finance_exchange_rates', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('currency',3); $t->string('base_currency',3);
            $t->decimal('rate_to_base',24,10); $t->date('effective_on');
            $t->string('status',18)->default('proposed');
            $t->string('source_note',250);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','currency','effective_on']);
            $t->unique(['business_id','id']);
        });
        Schema::create('finance_supplier_bills', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('purchase_order_id'); $t->string('reference',65);
            $t->string('vendor_invoice_reference',120);
            $t->date('vendor_invoice_date');
            $t->decimal('accepted_quantity',14,3); $t->decimal('total',20,2);
            $t->string('currency',3); $t->string('posting_status',20)->default('not_posted');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique('purchase_order_id'); $t->unique(['business_id','reference']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','purchase_order_id'])
                ->references(['business_id','id'])->on('purchase_orders')->restrictOnDelete();
        });
        Schema::create('finance_payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',55); $t->string('direction',12);
            $t->unsignedBigInteger('commercial_invoice_id')->nullable();
            $t->unsignedBigInteger('supplier_bill_id')->nullable();
            $t->unsignedBigInteger('cash_account_id');
            $t->string('currency',3); $t->decimal('amount',20,2);
            $t->decimal('settled_base_amount',20,2)->nullable();
            $t->date('payment_on'); $t->string('external_reference',150);
            $t->string('status',20)->default('pending');
            $t->text('notes')->nullable();
            $t->uuid('idempotency_key');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','idempotency_key']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','commercial_invoice_id'])
                ->references(['business_id','id'])->on('commercial_invoices')->restrictOnDelete();
            $t->foreign(['business_id','supplier_bill_id'])
                ->references(['business_id','id'])->on('finance_supplier_bills')->restrictOnDelete();
            $t->foreign(['business_id','cash_account_id'])
                ->references(['business_id','id'])->on('finance_accounts')->restrictOnDelete();
            $t->index(['business_id','direction','status']);
        });
        Schema::create('finance_shipment_costs', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->string('reference',55); $t->string('category',32);
            $t->string('description',250);
            $t->string('currency',3); $t->decimal('amount',20,2);
            $t->unsignedBigInteger('cash_account_id');
            $t->date('paid_on'); $t->string('external_reference',150);
            $t->string('status',20)->default('pending');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','shipment_id'])
                ->references(['business_id','id'])->on('export_shipments')->restrictOnDelete();
            $t->foreign(['business_id','cash_account_id'])
                ->references(['business_id','id'])->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_posting_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('source_type',28); $t->unsignedBigInteger('source_id');
            $t->string('status',18)->default('pending');
            $t->date('posting_on'); $t->unsignedBigInteger('exchange_rate_id')->nullable();
            $t->text('basis_notes');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','source_type','source_id']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','exchange_rate_id'])
                ->references(['business_id','id'])->on('finance_exchange_rates')->restrictOnDelete();
            $t->index(['business_id','status']);
        });
        Schema::create('finance_approval_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('posting_request_id'); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',24); $t->text('reason')->nullable(); $t->timestamp('created_at');
            $t->foreign(['business_id','posting_request_id'])
                ->references(['business_id','id'])->on('finance_posting_requests')->restrictOnDelete();
        });
        Schema::create('finance_journals', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',55); $t->string('source_type',28);
            $t->unsignedBigInteger('source_id'); $t->date('posted_on');
            $t->string('currency',3); $t->string('base_currency',3);
            $t->decimal('rate_to_base',24,10); $t->string('description',250);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['business_id','source_type','source_id']);
            $t->unique(['business_id','reference']); $t->unique(['business_id','id']);
            $t->index(['business_id','posted_on']);
        });
        Schema::create('finance_journal_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('journal_id'); $t->unsignedBigInteger('account_id');
            $t->decimal('debit',20,2)->default(0); $t->decimal('credit',20,2)->default(0);
            $t->decimal('base_debit',20,2)->default(0); $t->decimal('base_credit',20,2)->default(0);
            $t->string('memo',180)->nullable(); $t->timestamps();
            $t->foreign(['business_id','journal_id'])
                ->references(['business_id','id'])->on('finance_journals')->restrictOnDelete();
            $t->foreign(['business_id','account_id'])
                ->references(['business_id','id'])->on('finance_accounts')->restrictOnDelete();
            $t->index(['business_id','account_id']);
        });
    }

    public function down():void
    {
        foreach ([
            'finance_journal_lines','finance_journals','finance_approval_events',
            'finance_posting_requests','finance_shipment_costs','finance_payments',
            'finance_supplier_bills','finance_exchange_rates','finance_accounts'
        ] as $name) Schema::dropIfExists($name);
    }
};
