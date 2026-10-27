<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_posting_requests', fn (Blueprint $t) => $t->json('cash_carrying_snapshot')->nullable());
        Schema::create('finance_cash_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('account_id')->constrained('finance_accounts');
            $t->foreignId('journal_id')->constrained('finance_journals');
            $t->string('source_type', 40);
            $t->unsignedBigInteger('source_id');
            $t->decimal('unrealized_released', 20, 2);
            $t->string('restores_source_type', 40)->nullable();
            $t->unsignedBigInteger('restores_source_id')->nullable();
            $t->decimal('restores_units', 20, 2)->default(0);
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'source_type', 'source_id', 'account_id'], 'cash_settlement_source_unique');
            $t->index(['business_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_settlements');
        Schema::table('finance_posting_requests', fn (Blueprint $t) => $t->dropColumn('cash_carrying_snapshot'));
    }
};
