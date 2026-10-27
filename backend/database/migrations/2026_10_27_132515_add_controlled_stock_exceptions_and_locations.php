<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('name', 120);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'name']);
        });
        Schema::table('stock_batches', function (Blueprint $t) {
            $t->foreignId('stock_location_id')->nullable()->constrained('stock_locations');
        });
        Schema::table('cartons', function (Blueprint $t) {
            $t->foreignId('stock_location_id')->nullable()->constrained('stock_locations');
        });
        foreach (DB::table('businesses')->orderBy('id')->cursor() as $b) {
            $id = DB::table('stock_locations')->insertGetId(['business_id' => $b->id, 'name' => 'Main packhouse', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('stock_batches')->where('business_id', $b->id)->update(['stock_location_id' => $id]);
            DB::table('cartons')->where('business_id', $b->id)->update(['stock_location_id' => $id]);
        }
        Schema::create('stock_location_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('batch_id')->nullable()->constrained('stock_batches');
            $t->foreignId('carton_id')->nullable()->constrained();
            $t->foreignId('from_location_id')->constrained('stock_locations');
            $t->foreignId('to_location_id')->constrained('stock_locations');
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 30);
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
        });
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('batch_id')->constrained('stock_batches');
            $t->string('reference', 65)->unique();
            $t->date('event_on');
            $t->decimal('expected_quantity', 18, 3);
            $t->decimal('counted_quantity', 18, 3);
            $t->decimal('quantity_delta', 18, 3);
            $t->string('unit', 30);
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2)->nullable();
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->string('posting_status', 20)->default('not_posted');
            $t->json('data_snapshot');
            $t->char('snapshot_hash', 64);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'batch_id', 'posting_status']);
        });
        Schema::create('carton_repacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('carton_id')->constrained();
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->json('data_snapshot');
            $t->char('snapshot_hash', 64);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'carton_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['carton_repacks', 'stock_adjustments', 'stock_location_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('cartons', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_location_id'));
        Schema::table('stock_batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_location_id'));
        Schema::dropIfExists('stock_locations');
    }
};
