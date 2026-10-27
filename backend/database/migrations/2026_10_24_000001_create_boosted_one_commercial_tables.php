<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('business_name', 150);
            $table->string('contact_name', 150);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40);
            $table->string('industry', 50);
            $table->string('business_size', 30);
            $table->string('requested_plan', 30);
            $table->string('billing_cycle', 20);
            $table->text('requirements')->nullable();
            $table->string('status', 24)->default('new');
            $table->text('admin_notes')->nullable();
            $table->timestamp('privacy_accepted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->unsignedBigInteger('monthly_price_ugx')->nullable();
            $table->unsignedBigInteger('quarterly_price_ugx')->nullable();
            $table->unsignedBigInteger('annual_price_ugx')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('billing_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('type', 30);
            $table->string('provider', 120);
            $table->string('account_name', 150);
            $table->string('account_number', 120);
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('billing_email', 255);
            $table->string('billing_cycle', 20);
            $table->unsignedBigInteger('price_ugx');
            $table->unsignedTinyInteger('tax_rate')->default(0);
            $table->string('status', 24)->default('pending');
            $table->date('starts_on');
            $table->date('next_invoice_on')->nullable();
            $table->date('paid_through')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->text('agreement_notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_invoice_on']);
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_subscription_id')->constrained('billing_subscriptions')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('billing_payment_method_id')->nullable()->constrained('billing_payment_methods')->restrictOnDelete();
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->string('business_name', 150);
            $table->string('billing_email', 255);
            $table->string('plan_name', 120);
            $table->string('billing_cycle', 20);
            $table->date('period_start');
            $table->date('period_end')->nullable();
            $table->date('due_on');
            $table->json('payment_details');
            $table->unsignedBigInteger('subtotal_ugx');
            $table->unsignedBigInteger('tax_ugx')->default(0);
            $table->unsignedBigInteger('total_ugx');
            $table->unsignedBigInteger('paid_ugx')->default(0);
            $table->string('status', 24)->default('issued');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['billing_subscription_id', 'period_start'], 'billing_invoice_unique_period');
            $table->index(['business_id', 'status', 'due_on']);
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignId('billing_payment_method_id')->constrained('billing_payment_methods')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_ugx');
            $table->string('reference', 150);
            $table->date('received_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['billing_payment_method_id', 'reference'], 'billing_payment_unique_reference');
        });
    }

    public function down(): void
    {
        foreach (['billing_payments', 'billing_invoices', 'billing_subscriptions',
            'billing_payment_methods', 'billing_plans', 'onboarding_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
