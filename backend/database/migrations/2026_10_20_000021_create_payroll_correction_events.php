<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_payroll_correction_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('payroll_reversal_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',20);
            $t->text('reason');
            $t->json('snapshot')->nullable();
            $t->timestamp('created_at');
            $t->index(['business_id','payroll_reversal_id','created_at'],'payroll_correction_audit');
            $t->foreign(['business_id','payroll_reversal_id'])
                ->references(['business_id','id'])->on('finance_payroll_reversals')->restrictOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('finance_payroll_correction_events');
    }
};
