<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('business_operating_policies',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->boolean('dispatch_approval_required')->default(false);
            $t->unsignedSmallInteger('buyer_link_days')->default(7);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique('business_id');
            $t->unique(['business_id','id']);
        });
        Schema::create('shipment_dispatch_reviews',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('ready_event_id');
            $t->string('snapshot_sha256',64);
            $t->string('status',20)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','shipment_id','ready_event_id'],'dispatch_one_review_per_ready');
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','shipment_id'])
                ->references(['business_id','id'])->on('export_shipments')->restrictOnDelete();
        });
        Schema::table('stock_batches',function(Blueprint $t){
            $t->boolean('is_quarantined')->default(false);
            $t->string('quality_hold_reason',500)->nullable();
            $t->foreignId('quality_hold_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('quality_held_at')->nullable();
        });
        Schema::create('stock_quality_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('batch_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',15);
            $t->string('reason',500);
            $t->timestamp('created_at');
            $t->foreign(['business_id','batch_id'])
                ->references(['business_id','id'])->on('stock_batches')->restrictOnDelete();
        });
        Schema::table('products',function(Blueprint $t){
            $t->decimal('reorder_point',14,3)->nullable();
        });
    }
    public function down():void
    {
        Schema::table('products',fn(Blueprint $t)=>$t->dropColumn('reorder_point'));
        Schema::dropIfExists('stock_quality_events');
        Schema::table('stock_batches',function(Blueprint $t){
            $t->dropConstrainedForeignId('quality_hold_by');
            $t->dropColumn(['is_quarantined','quality_hold_reason','quality_held_at']);
        });
        Schema::dropIfExists('shipment_dispatch_reviews');
        Schema::dropIfExists('business_operating_policies');
    }
};
