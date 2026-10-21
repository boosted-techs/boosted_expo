<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('finance_bank_profiles',function(Blueprint $t){
            $t->string('account_identifier_sha256',64)->nullable();
        });
        Schema::create('finance_bank_statement_attestations',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('statement_id');
            $t->unsignedBigInteger('bank_profile_id');
            $t->string('issuer_id',100);
            $t->string('issuer_key_id',100);
            $t->string('statement_reference',150);
            $t->string('public_key_sha256',64);
            $t->string('payload_sha256',64);
            $t->string('signature_sha256',64);
            $t->longText('signed_payload');
            $t->text('signature_base64');
            $t->timestamp('signed_at');
            $t->timestamp('verified_at');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','statement_id'],'bank_attestation_once');
            $t->unique(['issuer_id','statement_reference'],'bank_issuer_statement_once');
            $t->foreign(['business_id','statement_id'])->references(['business_id','id'])
                ->on('finance_bank_statements')->restrictOnDelete();
            $t->foreign(['business_id','bank_profile_id'])->references(['business_id','id'])
                ->on('finance_bank_profiles')->restrictOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('finance_bank_statement_attestations');
        Schema::table('finance_bank_profiles',fn(Blueprint $t)=>$t->dropColumn('account_identifier_sha256'));
    }
};