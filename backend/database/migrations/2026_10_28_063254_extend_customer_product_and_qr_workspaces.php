<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t): void {
            $t->string('business_name', 160)->nullable();
            $t->string('business_email')->nullable();
            $t->string('location', 500)->nullable();
        });
        Schema::table('businesses', function (Blueprint $t): void {
            $t->unsignedTinyInteger('statement_week_start')->default(1);
        });
        Schema::table('qr_identities', function (Blueprint $t): void {
            $t->string('access_pin_hash')->nullable();
            $t->unsignedInteger('access_version')->default(1);
        });
        Schema::table('sales_orders', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable());
        Schema::table('partner_followups', fn (Blueprint $t) => $t->softDeletes());
        Schema::create('product_codes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->string('country_code', 2)->nullable();
            $t->string('code', 80);
            $t->string('description', 160)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'product_id', 'customer_id', 'country_code', 'code'], 'product_code_context_unique');
            $t->index(['business_id', 'product_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_codes');
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('archived_at'));
        Schema::table('partner_followups', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('qr_identities', fn (Blueprint $t) => $t->dropColumn(['access_pin_hash', 'access_version']));
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('statement_week_start'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['business_name', 'business_email', 'location']));
    }
};
