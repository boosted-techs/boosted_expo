<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function tenantReference(Blueprint $table, string $column, string $target): void
    {
        $table->unsignedBigInteger($column)->nullable();
        $table->foreign(['business_id', $column])->references(['business_id', 'id'])->on($target)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::table('sales_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by')->nullable()->change();
            $t->string('source', 20)->default('staff');
            $t->string('buyer_reference', 120)->nullable();
            $t->string('destination', 200)->nullable();
            $t->uuid('submission_key')->nullable();
            $t->string('submission_hash', 64)->nullable();
            $t->unique(['business_id', 'submission_key']);
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('sales_quotation_id')->nullable()->change();
            foreach (['subtotal', 'discount_amount', 'tax_amount', 'grand_total'] as $c) {
                $t->decimal($c, 20, 2)->nullable()->change();
            }
            $t->string('pricing_status', 20)->default('accepted');
            $t->date('required_on')->nullable();
            $t->string('destination', 200)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_reason', 1000)->nullable();
        });
        Schema::table('sales_order_lines', function (Blueprint $t) {
            $t->decimal('unit_price', 18, 4)->nullable()->change();
            $t->decimal('line_total', 20, 2)->nullable()->change();
            $t->decimal('closed_quantity', 14, 3)->default(0);
            $t->unique(['business_id', 'id']);
        });
        Schema::create('customer_ordering_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'customer_id', 'customers');
            $t->string('recipient');
            $t->string('token_hash', 64)->unique();
            $t->json('product_ids');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->string('delivery_status', 20)->default('pending');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'sales_order_id', 'sales_orders');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 60);
            $t->text('reason');
            $t->json('details')->nullable();
            $t->text('customer_note')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'sales_order_id', 'id']);
        });
        Schema::create('market_trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('reference', 40);
            $t->string('location', 150);
            $t->timestamp('due_at');
            $t->decimal('budget', 20, 2)->nullable();
            $t->string('currency', 3)->default('UGX');
            $t->string('status', 20)->default('planned');
            $t->text('notes')->nullable();
            $t->uuid('request_key');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'request_key']);
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $this->tenantReference($t, 'market_trip_id', 'market_trips');
            $t->date('expected_on')->nullable();
        });
        Schema::create('order_supplies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'sales_order_line_id', 'sales_order_lines');
            $this->tenantReference($t, 'purchase_order_id', 'purchase_orders');
            $this->tenantReference($t, 'market_trip_id', 'market_trips');
            $this->tenantReference($t, 'origin_supply_id', 'order_supplies');
            $t->string('kind', 20);
            $t->decimal('quantity', 14, 3);
            $t->string('status', 20)->default('active');
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'sales_order_line_id', 'status']);
        });
        Schema::create('order_receipt_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'order_supply_id', 'order_supplies');
            $this->tenantReference($t, 'goods_receipt_id', 'goods_receipts');
            $t->decimal('received_quantity', 14, 3);
            $t->decimal('accepted_quantity', 14, 3);
            $t->decimal('rejected_quantity', 14, 3);
            $t->timestamps();
            $t->unique(['order_supply_id', 'goods_receipt_id']);
        });
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'sales_order_line_id', 'sales_order_lines');
            $this->tenantReference($t, 'batch_id', 'stock_batches');
            $this->tenantReference($t, 'order_supply_id', 'order_supplies');
            $t->decimal('quantity', 14, 3);
            $t->decimal('consumed_quantity', 14, 3)->default(0);
            $t->decimal('lost_quantity', 14, 3)->default(0);
            $t->timestamp('released_at')->nullable();
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'batch_id', 'released_at']);
        });
        Schema::table('packing_jobs', function (Blueprint $t) {
            $this->tenantReference($t, 'sales_order_line_id', 'sales_order_lines');
        });
        Schema::create('order_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'sales_order_id', 'sales_orders');
            $t->string('title', 150);
            $t->string('kind', 20);
            $t->timestamp('due_at');
            $t->string('status', 20)->default('assigned');
            $t->text('note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
        });
        Schema::create('transport_partners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('phone', 50)->nullable();
            $t->string('partner_code', 50);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'partner_code']);
        });
        Schema::create('transport_legs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'shipment_id', 'export_shipments');
            $this->tenantReference($t, 'transport_partner_id', 'transport_partners');
            $t->unsignedInteger('sequence');
            $t->string('mode', 15);
            $t->string('origin', 150);
            $t->string('destination', 150);
            $t->timestamp('due_at');
            $t->json('partner_snapshot');
            $t->string('driver_name', 150)->nullable();
            $t->string('vehicle_reference', 100)->nullable();
            $t->string('booking_reference', 100)->nullable();
            $t->json('carton_ids');
            $t->string('status', 20)->default('planned');
            $t->uuid('request_key');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'request_key']);
            $t->unique(['shipment_id', 'sequence']);
        });
        Schema::create('transport_handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->tenantReference($t, 'transport_leg_id', 'transport_legs');
            $t->string('action', 20);
            $t->string('from_person', 150);
            $t->string('to_person', 150);
            $t->string('evidence_reference', 200);
            $t->timestamp('occurred_at');
            $t->text('notes')->nullable();
            $t->uuid('request_key');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->unique(['transport_leg_id', 'action']);
        });
        Schema::table('finance_shipment_costs', function (Blueprint $t) {
            $this->tenantReference($t, 'transport_leg_id', 'transport_legs');
        });
    }

    public function down(): void
    {
        Schema::table('finance_shipment_costs', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'transport_leg_id']);
            $t->dropColumn('transport_leg_id');
        });
        foreach (['transport_handovers', 'transport_legs', 'transport_partners', 'order_tasks'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('packing_jobs', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'sales_order_line_id']);
            $t->dropColumn('sales_order_line_id');
        });
        foreach (['stock_reservations', 'order_receipt_allocations', 'order_supplies'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'market_trip_id']);
            $t->dropColumn(['market_trip_id', 'expected_on']);
        });
        foreach (['market_trips', 'order_events', 'customer_ordering_links'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('sales_order_lines', function (Blueprint $t) {
            $t->dropUnique(['business_id', 'id']);
            $t->dropColumn('closed_quantity');
        });
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['pricing_status', 'required_on', 'destination', 'closed_at', 'close_reason']));
        Schema::table('sales_requests', function (Blueprint $t) {
            $t->dropUnique(['business_id', 'submission_key']);
            $t->dropColumn(['source', 'buyer_reference', 'destination', 'submission_key', 'submission_hash']);
        });
        // Nullable historical commercial fields deliberately remain nullable on rollback: forcing values would invent pricing.
    }
};
