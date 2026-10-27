<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_agreement_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('loan_id')->constrained('loan_facilities');
            $t->date('effective_on');
            $t->json('before');
            $t->json('after');
            $t->string('snapshot_hash', 64);
            $t->text('reason');
            $t->string('status', 20);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'loan_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_agreement_amendments');
    }
};
