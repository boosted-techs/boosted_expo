<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_operations', fn (Blueprint $t) => $t->foreignId('owner_user_id')->nullable()->constrained('users'));
        Schema::table('finance_shipment_costs', function (Blueprint $t) {
            $t->string('idempotency_key', 64)->nullable();
            $t->string('payload_hash', 64)->nullable();
            $t->unique(['business_id', 'idempotency_key'], 'shipment_cost_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_operations', fn (Blueprint $t) => $t->dropConstrainedForeignId('owner_user_id'));
        Schema::table('finance_shipment_costs', function (Blueprint $t) {
            $t->dropUnique('shipment_cost_request_unique');
            $t->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
