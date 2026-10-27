<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exception_cases', function (Blueprint $t) {
            $t->unique(['business_id', 'id'], 'stock_case_tenant_id_unique');
            $t->date('due_on')->nullable();
            $t->index(['business_id', 'status', 'due_on'], 'stock_case_due_actions');
        });
        Schema::create('stock_case_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->unsignedBigInteger('case_id');
            $t->string('path');
            $t->string('name', 180);
            $t->string('mime', 100);
            $t->unsignedBigInteger('bytes');
            $t->string('sha256', 64);
            $t->text('note');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->foreign(['business_id', 'case_id'])->references(['business_id', 'id'])->on('stock_exception_cases');
            $t->unique(['business_id', 'case_id', 'sha256'], 'stock_case_evidence_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_case_evidence');
        Schema::table('stock_exception_cases', function (Blueprint $t) {
            $t->dropUnique('stock_case_tenant_id_unique');
            $t->dropIndex('stock_case_due_actions');
            $t->dropColumn('due_on');
        });
    }
};
