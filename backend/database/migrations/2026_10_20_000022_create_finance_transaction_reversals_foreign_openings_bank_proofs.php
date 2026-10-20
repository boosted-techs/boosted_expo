<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_transaction_reversals',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);$t->string('source_type',30);$t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('original_journal_id');$t->unsignedBigInteger('cash_account_id');
            $t->string('currency',3);$t->decimal('amount',20,2);
            $t->text('reason');$t->text('external_evidence');
            $t->json('original_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','source_type','source_id'],'finance_one_payment_reversal');
            $t->unique(['business_id','original_journal_id'],'finance_original_payment_reversed_once');
            $t->foreign(['business_id','original_journal_id'])->references(['business_id','id'])
                ->on('finance_journals')->restrictOnDelete();
            $t->foreign(['business_id','cash_account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_foreign_bank_openings',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('bank_profile_id');$t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('exchange_rate_id');
            $t->string('reference',65);$t->string('currency',3);
            $t->decimal('foreign_amount',20,2);$t->decimal('base_amount',20,2);
            $t->decimal('rate_to_base',24,10);$t->date('effective_on');
            $t->text('bank_evidence');$t->json('data_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','id']);$t->unique(['business_id','reference']);
            $t->unique(['business_id','account_id'],'finance_foreign_bank_once');
            $t->foreign(['business_id','bank_profile_id'])->references(['business_id','id'])
                ->on('finance_bank_profiles')->restrictOnDelete();
            $t->foreign(['business_id','account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
            $t->foreign(['business_id','exchange_rate_id'])->references(['business_id','id'])
                ->on('finance_exchange_rates')->restrictOnDelete();
        });
        Schema::create('finance_bank_ledger_checks',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('statement_id');$t->string('status',20)->default('pending');
            $t->json('data_snapshot');$t->string('sha256',64);
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_notes')->nullable();$t->timestamp('verified_at')->nullable();
            $t->timestamps();$t->unique(['business_id','id']);
            $t->unique(['business_id','statement_id'],'finance_one_ledger_check_per_statement');
            $t->foreign(['business_id','statement_id'])->references(['business_id','id'])
                ->on('finance_bank_statements')->restrictOnDelete();
        });
        Schema::create('finance_bank_ledger_check_events',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('check_id');$t->foreignId('actor_id')
                ->constrained('users')->restrictOnDelete();
            $t->string('action',20);$t->text('notes');$t->json('snapshot')->nullable();
            $t->timestamp('created_at');
            $t->foreign(['business_id','check_id'])->references(['business_id','id'])
                ->on('finance_bank_ledger_checks')->restrictOnDelete();
        });
    }
    public function down():void
    {
        foreach(['finance_bank_ledger_check_events','finance_bank_ledger_checks',
            'finance_foreign_bank_openings','finance_transaction_reversals'] as $table)
            Schema::dropIfExists($table);
    }
};
