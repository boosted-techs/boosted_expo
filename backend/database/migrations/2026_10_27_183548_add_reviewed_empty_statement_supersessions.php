<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_bank_statements', function (Blueprint $t) {
            $t->dropUnique('bank_file_dedupe');
            $t->unique(['business_id', 'account_id', 'file_sha256', 'period_from', 'period_to', 'opening_balance', 'closing_balance'], 'bank_balance_file_dedupe');
        });
        Schema::table('finance_bank_ledger_checks', function (Blueprint $t) {
            $t->unsignedBigInteger('superseded_by_check_id')->nullable();
            $t->unsignedBigInteger('superseded_by')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->text('supersession_note')->nullable();
            $t->json('supersession_snapshot')->nullable();
            $t->string('supersession_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_bank_statements', function (Blueprint $t) {
            $t->dropUnique('bank_balance_file_dedupe');
            $t->unique(['business_id', 'account_id', 'file_sha256'], 'bank_file_dedupe');
        });
        Schema::table('finance_bank_ledger_checks', fn (Blueprint $t) => $t->dropColumn(['superseded_by_check_id', 'superseded_by', 'superseded_at', 'supersession_note', 'supersession_snapshot', 'supersession_hash']));
    }
};
