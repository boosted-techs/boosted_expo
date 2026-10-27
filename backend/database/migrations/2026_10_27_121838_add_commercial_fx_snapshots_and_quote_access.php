<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_exchange_rates', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->dropUnique(['business_id', 'currency', 'effective_on']);
            $table->unique(['business_id', 'currency', 'effective_on', 'revision'], 'fx_dated_revision_unique');
        });
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->foreignId('pricing_exchange_rate_id')->nullable()->constrained('finance_exchange_rates');
            $table->json('pricing_snapshot')->nullable();
            $table->string('currency_override_reason', 250)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });
        Schema::table('sales_orders', fn (Blueprint $table) => $table->unsignedBigInteger('confirmed_by')->nullable()->change());
        Schema::table('commercial_invoices', fn (Blueprint $table) => $table->date('due_on')->nullable());
        Schema::table('finance_supplier_bills', fn (Blueprint $table) => $table->date('due_on')->nullable());
        Schema::create('quotation_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('sales_quotation_id')->constrained();
            $table->char('token_hash', 64)->unique();
            $table->string('recipient_email');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('decision', 20)->nullable();
            $table->string('buyer_name', 120)->nullable();
            $table->string('buyer_reference', 160)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['business_id', 'sales_quotation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('finance_exchange_rates')->select('business_id', 'currency', 'effective_on')->groupBy('business_id', 'currency', 'effective_on')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rate revisions exist. Preserve their history before reverting this migration.');
        }
        Schema::dropIfExists('quotation_accesses');
        Schema::table('finance_supplier_bills', fn (Blueprint $table) => $table->dropColumn('due_on'));
        Schema::table('commercial_invoices', fn (Blueprint $table) => $table->dropColumn('due_on'));
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_exchange_rate_id');
            $table->dropColumn(['pricing_snapshot', 'payment_terms_days', 'currency_override_reason']);
        });
        // The preflight above prevents collapsing existing approved rate history.
        Schema::table('finance_exchange_rates', fn (Blueprint $table) => $table->dropUnique('fx_dated_revision_unique'));
        Schema::table('finance_exchange_rates', function (Blueprint $table) {
            $table->dropColumn('revision');
            $table->unique(['business_id', 'currency', 'effective_on']);
        });
    }
};
