<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('external_provider_connections',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('kind',20);
            $t->string('provider_code',60);
            $t->string('provider_account',120);
            $t->text('signing_secret');
            $t->string('trust_reference',200);
            $t->boolean('active')->default(false);
            $t->timestamps();
            $t->unique(['kind','provider_code','provider_account'],'provider_claim_unique');
            $t->unique(['business_id','id']);
        });
        Schema::create('external_provider_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('connection_id');
            $t->string('kind',20);
            $t->string('provider_event_id',120);
            $t->string('event_type',40);
            $t->string('external_reference',180);
            $t->string('currency',3)->nullable();
            $t->decimal('amount',20,2)->nullable();
            $t->unsignedBigInteger('finance_payment_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamp('provider_signed_at');
            $t->string('payload_sha256',64);
            $t->longText('encrypted_payload');
            $t->string('review_status',22)->default('needs_review');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['connection_id','provider_event_id'],'provider_event_replay_unique');
            $t->foreign(['business_id','connection_id'])->references(['business_id','id'])
                ->on('external_provider_connections')->restrictOnDelete();
            $t->index(['business_id','kind','review_status']);
        });
        Schema::create('label_print_agents',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name',100);
            $t->string('credential_hash',64)->unique();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
        });
        Schema::create('label_print_jobs',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('agent_id');
            $t->unsignedBigInteger('generation_id');
            $t->uuid('request_key');
            $t->string('status',18)->default('queued');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('lease_hash',64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('transferred_at')->nullable();
            $t->string('failure_note',250)->nullable();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','request_key']);
            $t->foreign(['business_id','agent_id'])->references(['business_id','id'])
                ->on('label_print_agents')->restrictOnDelete();
            $t->foreign('generation_id')->references('id')
                ->on('label_generations')->restrictOnDelete();
            $t->index(['business_id','agent_id','status']);
        });
        Schema::create('export_clearance_checks',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->string('document_kind',60);
            $t->string('authority_name',180);
            $t->string('external_reference',180);
            $t->date('valid_until')->nullable();
            $t->text('source_evidence');
            $t->string('status',22)->default('pending_review');
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','shipment_id'])->references(['business_id','id'])
                ->on('export_shipments')->restrictOnDelete();
            $t->index(['business_id','shipment_id','status']);
        });
    }

    public function down():void
    {
        Schema::dropIfExists('export_clearance_checks');
        Schema::dropIfExists('label_print_jobs');
        Schema::dropIfExists('label_print_agents');
        Schema::dropIfExists('external_provider_events');
        Schema::dropIfExists('external_provider_connections');
    }
};
