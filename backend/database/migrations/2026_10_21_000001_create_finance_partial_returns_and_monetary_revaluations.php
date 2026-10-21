<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_partial_bank_returns',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);
            $t->string('source_type',30);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('original_journal_id');
            $t->unsignedBigInteger('original_bank_match_id');
            $t->unsignedBigInteger('return_bank_line_id');
            $t->unsignedBigInteger('cash_account_id');
            $t->string('currency',3);
            $t->decimal('amount',20,2);
            $t->text('reason');
            $t->text('external_evidence');
            $t->json('data_snapshot');
            $t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','return_bank_line_id'],'partial_return_bank_line_unique');
            $t->index(['business_id','source_type','source_id'],'partial_return_source_index');
            $t->foreign(['business_id','original_journal_id'])->references(['business_id','id'])
                ->on('finance_journals')->restrictOnDelete();
            $t->foreign(['business_id','original_bank_match_id'])->references(['business_id','id'])
                ->on('finance_bank_matches')->restrictOnDelete();
            $t->foreign(['business_id','return_bank_line_id'])->references(['business_id','id'])
                ->on('finance_bank_statement_lines')->restrictOnDelete();
            $t->foreign(['business_id','cash_account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_monetary_revaluations',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);
            $t->string('source_type',24);
            $t->unsignedBigInteger('source_id');
            $t->string('currency',3);
            $t->date('valued_on');
            $t->unsignedBigInteger('exchange_rate_id');
            $t->decimal('foreign_balance',20,2);
            $t->decimal('previous_base',20,2);
            $t->decimal('revalued_base',20,2);
            $t->decimal('change_base',20,2);
            $t->decimal('rate_to_base',24,10);
            $t->text('valuation_evidence');
            $t->json('data_snapshot');
            $t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','source_type','source_id','valued_on'],'monetary_reval_unique');
            $t->foreign(['business_id','exchange_rate_id'])->references(['business_id','id'])
                ->on('finance_exchange_rates')->restrictOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('finance_monetary_revaluations');
        Schema::dropIfExists('finance_partial_bank_returns');
    }
};