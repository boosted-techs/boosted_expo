<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_workflow_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('original_workflow_id')->constrained('finance_workflows');
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->timestamps();
            $t->index(['business_id', 'original_workflow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_workflow_corrections');
    }
};
