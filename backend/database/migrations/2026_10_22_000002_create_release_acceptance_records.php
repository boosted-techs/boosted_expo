<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('release_acceptance_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->char('artifact_sha256',64);
            $t->string('release_label',100);
            $t->string('status',16)->default('open');
            $t->text('scope_note');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('sealed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('sealed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->index(['business_id','artifact_sha256','id'],'release_acceptance_candidate');
        });
        Schema::create('release_acceptance_checks',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('run_id');
            $t->string('check_code',38);
            $t->string('status',18)->default('pending');
            $t->string('evidence_reference',500)->nullable();
            $t->text('test_notes')->nullable();
            $t->foreignId('tested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('tested_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_notes')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','run_id','check_code'],'release_checks_unique');
            $t->foreign(['business_id','run_id'])->references(['business_id','id'])
                ->on('release_acceptance_runs')->restrictOnDelete();
        });
        Schema::create('release_acceptance_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('check_id')->nullable();
            $t->string('action',22);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->char('evidence_sha256',64)->nullable();
            $t->timestamp('created_at');
            $t->foreign(['business_id','run_id'])->references(['business_id','id'])
                ->on('release_acceptance_runs')->restrictOnDelete();
            $t->foreign(['business_id','check_id'])->references(['business_id','id'])
                ->on('release_acceptance_checks')->restrictOnDelete();
            $t->index(['business_id','run_id','id']);
        });
    }

    public function down():void
    {
        Schema::dropIfExists('release_acceptance_events');
        Schema::dropIfExists('release_acceptance_checks');
        Schema::dropIfExists('release_acceptance_runs');
    }
};
