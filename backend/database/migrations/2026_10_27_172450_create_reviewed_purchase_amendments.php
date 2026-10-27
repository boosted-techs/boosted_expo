<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('purchase_order_id')->constrained();
            $t->string('status', 20);
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->string('base_hash', 64);
            $t->text('reason');
            $t->string('agreement_reference', 200);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'purchase_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_amendments');
    }
};
