<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_period_reopen_requests',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('period_id');
            $t->string('status',20)->default('pending');
            $t->text('reason');
            $t->text('materiality_assessment');
            $t->text('external_evidence');
            $t->json('original_close_snapshot');
            $t->string('original_close_hash',64);
            $t->timestamp('original_closed_at');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_notes')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->index(['business_id','period_id','status'],'finance_period_reopen_active');
            $t->foreign(['business_id','period_id'])->references(['business_id','id'])
                ->on('finance_periods')->restrictOnDelete();
        });
    }
    public function down():void {
        Schema::dropIfExists('finance_period_reopen_requests');
    }
};