<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('buyer_order_links',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sales_order_id');
            $t->string('buyer_email',255);
            $t->string('token_hash',64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->index(['business_id','sales_order_id','revoked_at']);
            $t->foreign(['business_id','sales_order_id'])
                ->references(['business_id','id'])->on('sales_orders')->restrictOnDelete();
        });
        Schema::create('team_membership_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('membership_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',20);
            $t->string('previous_role',25);
            $t->string('new_role',25);
            $t->boolean('previous_active');
            $t->boolean('new_active');
            $t->string('reason',500);
            $t->timestamp('created_at');
            $t->foreign(['membership_id'])->references(['id'])
                ->on('business_memberships')->restrictOnDelete();
            $t->index(['business_id','membership_id','created_at']);
        });
    }
    public function down():void
    {
        Schema::dropIfExists('team_membership_events');
        Schema::dropIfExists('buyer_order_links');
    }
};
