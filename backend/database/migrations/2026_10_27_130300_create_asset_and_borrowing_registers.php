<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('asset_code', 65);
            $t->string('name', 150);
            $t->string('category', 80);
            $t->string('ownership', 20);
            $t->string('serial_number', 120)->nullable();
            $t->string('purchase_reference', 150)->nullable();
            $t->string('location', 150);
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->string('condition', 30)->default('good');
            $t->string('operating_status', 30)->default('available');
            $t->unsignedInteger('useful_life_months')->nullable();
            $t->decimal('residual_base', 20, 2)->default(0);
            $t->date('depreciation_start_on')->nullable();
            $t->date('maintenance_due_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['business_id', 'asset_code']);
            $t->unique(['business_id', 'id']);
            $t->index(['business_id', 'maintenance_due_on']);
        });
        Schema::create('asset_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->unsignedBigInteger('asset_id');
            $t->string('kind', 40);
            $t->date('event_on');
            $t->string('reference', 150);
            $t->text('note');
            $t->json('before');
            $t->json('after');
            $t->foreignId('expense_id')->nullable()->constrained('finance_operations');
            $t->foreignId('created_by')->constrained('users');
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->foreign(['business_id', 'asset_id'])->references(['business_id', 'id'])->on('business_assets');
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'asset_id', 'event_on']);
        });
        Schema::create('loan_facilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 65);
            $t->string('lender_name', 150);
            $t->string('lender_kind', 20)->default('external');
            $t->string('currency', 3);
            $t->decimal('limit_amount', 20, 2);
            $t->boolean('revolving')->default(false);
            $t->date('starts_on');
            $t->date('matures_on');
            $t->string('agreement_reference', 200);
            $t->text('terms');
            $t->string('status', 20)->default('active');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['business_id', 'reference']);
            $t->unique(['business_id', 'id']);
            $t->index(['business_id', 'status', 'matures_on']);
        });
        Schema::create('loan_instalments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->unsignedBigInteger('loan_id');
            $t->date('due_on');
            $t->decimal('principal', 20, 2);
            $t->decimal('interest', 20, 2);
            $t->decimal('fees', 20, 2);
            $t->string('reference', 150);
            $t->string('status', 20)->default('active');
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->foreign(['business_id', 'loan_id'])->references(['business_id', 'id'])->on('loan_facilities');
            $t->unique(['business_id', 'loan_id', 'reference']);
            $t->index(['business_id', 'loan_id', 'due_on']);
        });
        Schema::create('finance_register_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 65)->unique();
            $t->string('kind', 40);
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->unsignedBigInteger('loan_id')->nullable();
            $t->foreignId('instalment_id')->nullable()->constrained('loan_instalments');
            $t->foreignId('original_entry_id')->nullable()->constrained('finance_register_entries');
            $t->date('event_on');
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->foreignId('exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $t->decimal('rate_to_base', 24, 10);
            $t->foreignId('cash_account_id')->nullable()->constrained('finance_accounts');
            $t->string('cash_direction', 3)->nullable();
            $t->decimal('cash_amount', 20, 2)->default(0);
            $t->foreignId('return_bank_line_id')->nullable()->constrained('finance_bank_statement_lines');
            $t->string('external_reference', 150);
            $t->text('reason');
            $t->string('posting_status', 20)->default('not_posted');
            $t->json('data_snapshot');
            $t->char('snapshot_hash', 64);
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->foreign(['business_id', 'asset_id'])->references(['business_id', 'id'])->on('business_assets');
            $t->foreign(['business_id', 'loan_id'])->references(['business_id', 'id'])->on('loan_facilities');
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'asset_id', 'posting_status']);
            $t->index(['business_id', 'loan_id', 'posting_status']);
            $t->index(['business_id', 'posting_status', 'event_on']);
        });
    }

    public function down(): void
    {
        foreach (['finance_register_entries', 'loan_instalments', 'loan_facilities', 'asset_activities', 'business_assets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
