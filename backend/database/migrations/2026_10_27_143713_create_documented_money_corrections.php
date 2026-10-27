<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_money_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('operation_id')->constrained('finance_operations');
            $t->foreignId('workflow_id')->constrained('finance_workflows');
            $t->foreignId('original_journal_id')->constrained('finance_journals');
            $t->decimal('amount', 20, 2);
            $t->decimal('control_base', 20, 2);
            $t->json('data_snapshot');
            $t->timestamps();
            $t->unique('workflow_id');
            $t->index(['business_id', 'operation_id']);
        });
        Schema::create('finance_workflow_bank_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->constrained('finance_workflows');
            $t->foreignId('leg_id')->constrained('finance_workflow_legs');
            $t->foreignId('statement_line_id')->constrained('finance_bank_statement_lines');
            $t->json('data_snapshot');
            $t->timestamps();
            $t->unique(['workflow_id', 'statement_line_id']);
            $t->index(['business_id', 'statement_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_workflow_bank_evidence');
        Schema::dropIfExists('finance_money_corrections');
    }
};
