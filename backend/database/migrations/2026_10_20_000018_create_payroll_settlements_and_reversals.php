<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('finance_payroll_lines',function(Blueprint $t){
            $t->unique(['business_id','payroll_run_id','id'],'payroll_line_business_run_id');
        });
        Schema::create('finance_payroll_settlements',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('payroll_run_id');
            $t->unsignedBigInteger('payroll_line_id')->nullable();
            $t->unsignedBigInteger('cash_account_id');
            $t->string('reference',65);
            $t->string('kind',15); // salary / withholding
            $t->string('recipient_name',150)->nullable(); // withholding recipient only
            $t->string('currency',3);
            $t->decimal('amount',20,2);
            $t->date('paid_on');
            $t->string('external_reference',150);
            $t->text('evidence_note');
            $t->string('status',20)->default('pending');
            $t->string('idempotency_key',80);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','idempotency_key'],'payroll_settlement_idempotency');
            $t->index(['business_id','payroll_run_id','kind','status'],'payroll_settlement_lookup');
            $t->foreign(['business_id','payroll_run_id'])
                ->references(['business_id','id'])->on('finance_payroll_runs')->restrictOnDelete();
            $t->foreign(['business_id','payroll_run_id','payroll_line_id'],'settlement_employee_run_fk')
                ->references(['business_id','payroll_run_id','id'])->on('finance_payroll_lines')
                ->restrictOnDelete();
            $t->foreign(['business_id','cash_account_id'])
                ->references(['business_id','id'])->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_payroll_reversals',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('payroll_run_id');
            $t->unsignedBigInteger('original_journal_id');
            $t->string('reference',65);$t->string('currency',3);
            $t->text('reason');
            $t->json('original_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','payroll_run_id'],'payroll_one_reversal');
            $t->unique(['business_id','original_journal_id'],'payroll_original_once');
            $t->foreign(['business_id','payroll_run_id'])
                ->references(['business_id','id'])->on('finance_payroll_runs')->restrictOnDelete();
            $t->foreign(['business_id','original_journal_id'])
                ->references(['business_id','id'])->on('finance_journals')->restrictOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('finance_payroll_reversals');
        Schema::dropIfExists('finance_payroll_settlements');
        Schema::table('finance_payroll_lines',function(Blueprint $t){
            $t->dropUnique('payroll_line_business_run_id');
        });
    }
};
