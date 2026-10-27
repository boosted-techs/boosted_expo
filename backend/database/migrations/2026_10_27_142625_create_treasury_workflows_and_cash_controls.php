<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_workflows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 65)->unique();
            $t->string('kind', 50);
            $t->date('event_on');
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->decimal('rate_to_base', 24, 10);
            $t->foreignId('exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $t->text('reason');
            $t->string('external_reference', 150);
            $t->string('posting_status', 20)->default('not_posted');
            $t->json('data_snapshot');
            $t->char('snapshot_hash', 64);
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'posting_status', 'event_on']);
        });
        Schema::create('finance_workflow_legs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->constrained('finance_workflows');
            $t->unsignedInteger('sequence');
            $t->string('currency', 3);
            $t->decimal('rate_to_base', 24, 10);
            $t->foreignId('exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $t->foreignId('cash_account_id')->nullable()->constrained('finance_accounts');
            $t->string('cash_direction', 3)->nullable();
            $t->decimal('amount', 20, 2);
            $t->date('event_on');
            $t->json('lines');
            $t->string('posting_status', 20)->default('not_posted');
            $t->timestamps();
            $t->unique(['workflow_id', 'sequence']);
            $t->index(['business_id', 'cash_account_id', 'posting_status']);
        });
        Schema::create('cash_custodies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('account_id')->constrained('finance_accounts');
            $t->string('name', 120);
            $t->string('currency', 3);
            $t->foreignId('staff_person_id')->constrained('staff_people');
            $t->decimal('authorised_float', 20, 2);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['business_id', 'name']);
            $t->unique('account_id');
        });
        Schema::create('cash_closes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('custody_id')->constrained('cash_custodies');
            $t->date('counted_on');
            $t->decimal('expected', 20, 2);
            $t->decimal('counted', 20, 2);
            $t->foreignId('exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $t->decimal('rate_to_base', 24, 10);
            $t->char('ledger_fingerprint', 64);
            $t->json('denominations')->nullable();
            $t->text('reason');
            $t->json('data_snapshot')->nullable();
            $t->string('snapshot_hash', 64)->nullable();
            $t->string('status', 20)->default('pending');
            $t->foreignId('workflow_id')->nullable()->constrained('finance_workflows');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'custody_id', 'counted_on']);
        });
        Schema::create('recurring_obligations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('name', 150);
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->string('category', 50);
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->date('next_due_on');
            $t->unsignedInteger('interval_months')->default(1);
            $t->boolean('active')->default(true);
            $t->text('terms');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['business_id', 'active', 'next_due_on']);
        });
        Schema::create('recurring_occurrences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('obligation_id')->constrained('recurring_obligations');
            $t->date('due_on');
            $t->foreignId('operation_id')->constrained('finance_operations');
            $t->timestamps();
            $t->unique(['obligation_id', 'due_on']);
        });
    }

    public function down(): void
    {
        foreach (['recurring_occurrences', 'recurring_obligations', 'cash_closes', 'cash_custodies', 'finance_workflow_legs', 'finance_workflows'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
