<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->string('name', 150);
            $t->string('unit', 40);
            $t->string('location', 150);
            $t->decimal('quantity', 18, 3);
            $t->decimal('base_cost', 20, 2);
            $t->timestamps();
            $t->index(['business_id', 'name']);
        });
        Schema::create('material_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('material_lot_id')->constrained();
            $t->foreignId('workflow_id')->constrained('finance_workflows');
            $t->decimal('quantity', 18, 3);
            $t->decimal('base_cost', 20, 2);
            $t->timestamps();
            $t->unique(['workflow_id', 'material_lot_id']);
        });
        Schema::create('finance_cost_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->string('source_type', 30);
            $t->unsignedBigInteger('source_id');
            $t->string('account', 80);
            $t->decimal('base_amount', 20, 2);
            $t->string('source_hash', 64);
            $t->timestamps();
            $t->index(['business_id', 'source_type', 'source_id']);
        });
        Schema::create('finance_cost_layers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->foreignId('batch_id')->constrained('stock_batches');
            $t->decimal('base_amount', 20, 2);
            $t->decimal('inventory_amount', 20, 2);
            $t->decimal('cogs_amount', 20, 2);
            $t->decimal('waste_amount', 20, 2);
            $t->timestamps();
            $t->index(['business_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cost_layers');
        Schema::dropIfExists('finance_cost_claims');
        Schema::dropIfExists('material_movements');
        Schema::dropIfExists('material_lots');
    }
};
