<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_finance_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $t->string('currency', 3);
            $t->unsignedInteger('payment_days');
            $t->decimal('credit_limit', 20, 2)->nullable();
            $t->date('effective_on');
            $t->text('agreement');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['business_id', 'customer_id', 'currency', 'effective_on']);
            $t->index(['business_id', 'supplier_id', 'currency', 'effective_on']);
        });
        Schema::table('sales_quotations', fn (Blueprint $t) => $t->foreignId('partner_finance_term_id')->nullable()->constrained('partner_finance_terms'));
        Schema::create('trade_collection_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('commercial_invoice_id')->nullable()->constrained('commercial_invoices');
            $t->foreignId('supplier_bill_id')->nullable()->constrained('finance_supplier_bills');
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->string('status', 30)->default('open');
            $t->date('next_action_on')->nullable();
            $t->date('promised_on')->nullable();
            $t->decimal('disputed_amount', 20, 2)->default(0);
            $t->text('note');
            $t->boolean('automatic')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['business_id', 'commercial_invoice_id']);
            $t->unique(['business_id', 'supplier_bill_id']);
            $t->index(['business_id', 'status', 'next_action_on']);
        });
        Schema::create('trade_collection_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('case_id')->constrained('trade_collection_cases');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action', 50);
            $t->json('before');
            $t->json('after');
            $t->text('note');
            $t->timestamps();
            $t->index(['business_id', 'case_id', 'id']);
        });
        Schema::create('advance_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 65)->unique();
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $t->foreignId('market_trip_id')->nullable()->constrained('market_trips');
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->date('needed_on');
            $t->date('accountable_on');
            $t->text('reason');
            $t->string('status', 20)->default('requested');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'status', 'accountable_on']);
        });
        Schema::table('staff_people', fn (Blueprint $t) => $t->decimal('advance_limit_base', 20, 2)->nullable());
        Schema::table('finance_operations', fn (Blueprint $t) => $t->foreignId('advance_request_id')->nullable()->constrained('advance_requests'));
        Schema::create('market_accountabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('market_trip_id')->constrained('market_trips');
            $t->string('status', 20)->default('closed');
            $t->json('data_snapshot');
            $t->char('snapshot_hash', 64);
            $t->text('reason');
            $t->foreignId('closed_by')->constrained('users');
            $t->foreignId('reopened_by')->nullable()->constrained('users');
            $t->timestamp('reopened_at')->nullable();
            $t->text('reopen_reason')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'market_trip_id', 'status']);
        });
        Schema::create('trade_credit_exceptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('shipment_id')->constrained('export_shipments');
            $t->string('currency', 3);
            $t->decimal('exposure_limit', 20, 2);
            $t->date('expires_on');
            $t->text('reason');
            $t->foreignId('approved_by')->constrained('users');
            $t->foreignId('commercial_invoice_id')->nullable()->constrained('commercial_invoices');
            $t->timestamps();
            $t->index(['business_id', 'shipment_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_credit_exceptions');
        Schema::dropIfExists('market_accountabilities');
        Schema::table('finance_operations', fn (Blueprint $t) => $t->dropConstrainedForeignId('advance_request_id'));
        Schema::table('staff_people', fn (Blueprint $t) => $t->dropColumn('advance_limit_base'));
        Schema::table('sales_quotations', fn (Blueprint $t) => $t->dropConstrainedForeignId('partner_finance_term_id'));
        foreach (['advance_requests', 'trade_collection_events', 'trade_collection_cases', 'partner_finance_terms'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
