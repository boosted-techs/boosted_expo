<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropUnique(['business_id', 'source_type', 'source_id', 'movement_type']);
            $t->unique(['business_id', 'source_type', 'source_id', 'movement_type', 'batch_id'], 'stock_movement_source_batch_unique');
            $t->date('occurred_on')->nullable();
        });
        Schema::create('stock_exception_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 60);
            $t->string('kind', 30);
            $t->date('event_on');
            $t->foreignId('batch_id')->nullable()->constrained('stock_batches');
            $t->foreignId('carton_id')->nullable()->constrained();
            $t->foreignId('shipment_id')->nullable()->constrained('export_shipments');
            $t->foreignId('staff_person_id')->nullable()->constrained();
            $t->decimal('quantity', 18, 3)->nullable();
            $t->string('status', 30);
            $t->text('reason');
            $t->text('external_reference');
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->json('result')->nullable();
            $t->foreignId('workflow_id')->nullable()->constrained('finance_workflows');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'kind', 'status']);
        });
        Schema::create('stock_exception_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('case_id')->constrained('stock_exception_cases');
            $t->string('action', 40);
            $t->foreignId('shipment_id')->nullable()->constrained('export_shipments');
            $t->text('note');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['business_id', 'case_id']);
        });
        Schema::create('stock_batch_origins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('batch_id')->unique()->constrained('stock_batches');
            $t->foreignId('case_id')->nullable()->constrained('stock_exception_cases');
            $t->foreignId('source_batch_id')->nullable()->constrained('stock_batches');
            $t->foreignId('workflow_id')->nullable()->constrained('finance_workflows');
            $t->string('kind', 30);
            $t->decimal('quantity', 18, 3);
            $t->decimal('base_cost', 20, 2);
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->timestamps();
            $t->index(['business_id', 'source_batch_id']);
        });
        Schema::create('stock_batch_depletions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('batch_id')->constrained('stock_batches');
            $t->foreignId('case_id')->constrained('stock_exception_cases');
            $t->foreignId('workflow_id')->nullable()->constrained('finance_workflows');
            $t->string('kind', 30);
            $t->decimal('quantity', 18, 3);
            $t->decimal('base_cost', 20, 2);
            $t->timestamps();
            $t->unique(['case_id', 'batch_id']);
            $t->index(['business_id', 'batch_id']);
        });
        Schema::create('finance_cost_layer_reliefs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('cost_layer_id')->constrained('finance_cost_layers');
            $t->foreignId('inventory_relief_id')->constrained('finance_inventory_reliefs');
            $t->decimal('quantity_covered', 18, 3);
            $t->date('event_on');
            $t->string('expense_account', 60);
            $t->decimal('base_cost', 20, 2);
            $t->timestamps();
            $t->unique(['cost_layer_id', 'inventory_relief_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropUnique('stock_movement_source_batch_unique');
            $t->dropColumn('occurred_on');
            $t->unique(['business_id', 'source_type', 'source_id', 'movement_type']);
        });
        Schema::dropIfExists('finance_cost_layer_reliefs');
        Schema::dropIfExists('stock_batch_depletions');
        Schema::dropIfExists('stock_batch_origins');
        Schema::dropIfExists('stock_exception_events');
        Schema::dropIfExists('stock_exception_cases');
    }
};
