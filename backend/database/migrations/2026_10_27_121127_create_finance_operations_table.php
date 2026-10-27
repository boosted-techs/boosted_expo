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
        Schema::create('finance_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('reference')->unique();
            $table->string('kind', 40);
            $table->string('currency', 3);
            $table->decimal('amount', 20, 2);
            $table->date('event_on');
            $table->date('due_on')->nullable();
            $table->foreignId('exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $table->decimal('rate_to_base', 24, 10);
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $table->foreignId('sales_order_id')->nullable()->constrained();
            $table->foreignId('market_trip_id')->nullable()->constrained();
            $table->foreignId('shipment_id')->nullable()->constrained('export_shipments');
            $table->foreignId('parent_id')->nullable()->constrained('finance_operations');
            $table->foreignId('commercial_invoice_id')->nullable()->constrained();
            $table->foreignId('supplier_bill_id')->nullable()->constrained('finance_supplier_bills');
            $table->foreignId('cash_account_id')->nullable()->constrained('finance_accounts');
            $table->foreignId('destination_account_id')->nullable()->constrained('finance_accounts');
            $table->string('category', 60)->nullable();
            $table->string('description', 250);
            $table->string('external_reference', 150);
            $table->string('posting_status', 20)->default('not_posted');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->json('data_snapshot');
            $table->char('snapshot_hash', 64);
            $table->decimal('consumed_base', 20, 2)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'posting_status', 'event_on']);
            $table->index(['business_id', 'parent_id', 'posting_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_operations');
    }
};
