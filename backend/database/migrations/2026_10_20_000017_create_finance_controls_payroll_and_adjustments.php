<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_bank_profiles',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('account_id');
            $t->string('label',100);$t->string('institution',100);
            $t->string('currency',3);$t->string('masked_reference',32)->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','label']);$t->unique(['business_id','account_id']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_opening_balances',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);$t->date('effective_on');$t->string('currency',3);
            $t->json('data_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique('business_id');$t->unique(['business_id','reference']);
            $t->unique(['business_id','id']);
        });
        Schema::create('finance_periods',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->date('period_from');$t->date('period_to');
            $t->string('status',20)->default('open');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('reason')->nullable();$t->json('close_snapshot')->nullable();
            $t->string('close_hash',64)->nullable();$t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','period_from','period_to']);
            $t->unique(['business_id','id']);$t->index(['business_id','status','period_to']);
        });
        Schema::create('finance_period_events',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('period_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',20);$t->text('reason')->nullable();
            $t->json('snapshot')->nullable();$t->timestamp('created_at');
            $t->foreign(['business_id','period_id'])->references(['business_id','id'])
                ->on('finance_periods')->restrictOnDelete();
        });
        Schema::create('finance_balance_adjustments',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);$t->string('kind',12);
            $t->unsignedBigInteger('commercial_invoice_id')->nullable();
            $t->unsignedBigInteger('supplier_bill_id')->nullable();
            $t->string('currency',3);$t->decimal('amount',20,2);
            $t->text('reason');$t->json('data_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','reference']);$t->unique('commercial_invoice_id');
            $t->unique('supplier_bill_id');$t->unique(['business_id','id']);
            $t->foreign(['business_id','commercial_invoice_id'])
                ->references(['business_id','id'])->on('commercial_invoices')->restrictOnDelete();
            $t->foreign(['business_id','supplier_bill_id'])
                ->references(['business_id','id'])->on('finance_supplier_bills')->restrictOnDelete();
        });
        Schema::create('finance_payroll_runs',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);$t->date('period_from');$t->date('period_to');
            $t->string('currency',3);$t->decimal('gross_total',20,2);
            $t->decimal('deduction_total',20,2);$t->decimal('net_total',20,2);
            $t->string('posting_status',20)->default('not_posted');
            $t->text('notes');$t->json('data_snapshot');$t->string('sha256',64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','period_from','period_to']);
            $t->unique(['business_id','reference']);$t->unique(['business_id','id']);
        });
        Schema::create('finance_payroll_lines',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('payroll_run_id');$t->foreignId('user_id')
                ->constrained('users')->restrictOnDelete();
            $t->decimal('gross',20,2);$t->decimal('deductions',20,2);$t->decimal('net',20,2);
            $t->timestamps();$t->unique(['business_id','payroll_run_id','user_id'],'payroll_employee_one_per_run');
            $t->foreign(['business_id','payroll_run_id'])->references(['business_id','id'])
                ->on('finance_payroll_runs')->restrictOnDelete();
        });
    }
    public function down():void
    {
        foreach(['finance_payroll_lines','finance_payroll_runs','finance_balance_adjustments',
            'finance_period_events','finance_periods','finance_opening_balances',
            'finance_bank_profiles'] as $table) Schema::dropIfExists($table);
    }
};
