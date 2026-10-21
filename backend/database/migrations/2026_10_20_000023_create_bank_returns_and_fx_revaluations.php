<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('finance_transaction_reversals',function(Blueprint $t){
            $t->unsignedBigInteger('original_bank_match_id')->nullable();
            $t->unsignedBigInteger('return_bank_line_id')->nullable();
            $t->unique(['business_id','return_bank_line_id'],'fin_unique_return_bank_line');
            $t->foreign(['business_id','original_bank_match_id'],'fin_original_bank_match_fk')
                ->references(['business_id','id'])->on('finance_bank_matches')->restrictOnDelete();
            $t->foreign(['business_id','return_bank_line_id'],'fin_return_bank_line_fk')
                ->references(['business_id','id'])->on('finance_bank_statement_lines')->restrictOnDelete();
        });

        Schema::create('finance_fx_revaluations',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('bank_profile_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('exchange_rate_id');
            $t->string('reference',65);
            $t->string('currency',3);
            $t->date('valued_on');
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
            $t->unique(['business_id','account_id','valued_on'],'fin_fx_one_bank_per_valuation_date');
            $t->foreign(['business_id','bank_profile_id'])->references(['business_id','id'])
                ->on('finance_bank_profiles')->restrictOnDelete();
            $t->foreign(['business_id','account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
            $t->foreign(['business_id','exchange_rate_id'])->references(['business_id','id'])
                ->on('finance_exchange_rates')->restrictOnDelete();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('finance_fx_revaluations');
        Schema::table('finance_transaction_reversals',function(Blueprint $t){
            $t->dropForeign('fin_original_bank_match_fk');
            $t->dropForeign('fin_return_bank_line_fk');
            $t->dropUnique('fin_unique_return_bank_line');
            $t->dropColumn(['original_bank_match_id','return_bank_line_id']);
        });
    }
};
