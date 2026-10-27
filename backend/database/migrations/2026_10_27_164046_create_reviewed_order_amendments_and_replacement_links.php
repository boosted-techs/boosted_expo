<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('order_type', 25)->default('sale');
            $t->foreignId('replacement_case_id')->nullable()->constrained('stock_exception_cases');
            $t->foreignId('replacement_shipment_id')->nullable()->constrained('export_shipments');
            $t->json('pricing_snapshot')->nullable();
        });
        Schema::create('order_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('sales_order_id')->constrained();
            $t->string('reference', 60);
            $t->string('status', 30);
            $t->json('data_snapshot');
            $t->string('snapshot_hash', 64);
            $t->string('base_hash', 64);
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->string('buyer_mode', 20);
            $t->string('buyer_name', 150)->nullable();
            $t->string('buyer_reference', 200)->nullable();
            $t->text('buyer_evidence')->nullable();
            $t->timestamp('buyer_decided_at')->nullable();
            $t->string('token_hash', 64)->nullable()->unique();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'sales_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_amendments');
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replacement_case_id');
            $t->dropConstrainedForeignId('replacement_shipment_id');
            $t->dropColumn(['order_type', 'pricing_snapshot']);
        });
    }
};
