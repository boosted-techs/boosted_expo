<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cutovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 60);
            $t->date('effective_on');
            $t->string('status', 25);
            $t->string('source_file_path')->nullable();
            $t->string('source_file_sha256', 64)->nullable();
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->foreignId('workflow_id')->nullable()->constrained('finance_workflows');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('accepted_by')->nullable()->constrained('users');
            $t->timestamp('accepted_at')->nullable();
            $t->text('acceptance_note')->nullable();
            $t->json('acceptance_snapshot')->nullable();
            $t->string('acceptance_hash', 64)->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'status']);
        });
        Schema::create('finance_opening_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('cutover_id')->constrained('finance_cutovers');
            $t->foreignId('workflow_id')->constrained('finance_workflows');
            $t->foreignId('workflow_leg_id')->constrained('finance_workflow_legs');
            $t->string('kind', 30);
            $t->string('reference', 150);
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->decimal('base_amount', 20, 2);
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->foreignId('staff_person_id')->nullable()->constrained();
            $t->date('due_on')->nullable();
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'kind', 'reference']);
        });
        Schema::create('finance_opening_item_uses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('opening_item_id')->constrained('finance_opening_items');
            $t->foreignId('workflow_id')->unique()->constrained('finance_workflows');
            $t->string('kind', 30);
            $t->decimal('amount', 20, 2);
            $t->decimal('control_base', 20, 2);
            $t->decimal('target_control_base', 20, 2)->nullable();
            $t->foreignId('commercial_invoice_id')->nullable()->constrained();
            $t->foreignId('supplier_bill_id')->nullable()->constrained('finance_supplier_bills');
            $t->foreignId('expense_id')->nullable()->constrained('finance_operations');
            $t->timestamps();
            $t->index(['business_id', 'opening_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_opening_item_uses');
        Schema::dropIfExists('finance_opening_items');
        Schema::dropIfExists('finance_cutovers');
    }
};
