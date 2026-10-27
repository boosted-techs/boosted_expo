<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_journals', function (Blueprint $t) {
            $t->json('source_snapshot')->nullable();
            $t->string('source_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_journals', function (Blueprint $t) {
            $t->dropColumn(['source_snapshot', 'source_hash']);
        });
    }
};
