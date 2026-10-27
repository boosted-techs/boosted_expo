<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_commitments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('reference', 60);
            $t->string('kind', 30);
            $t->string('currency', 3);
            $t->decimal('amount', 20, 2);
            $t->date('due_on');
            $t->string('purpose', 150);
            $t->text('evidence');
            $t->string('status', 20)->default('proposed');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->index(['business_id', 'status', 'due_on']);
        });
        Schema::create('funding_recognitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('commitment_id')->constrained('funding_commitments');
            $t->string('source_type', 30);
            $t->unsignedBigInteger('source_id');
            $t->decimal('amount', 20, 2);
            $t->foreignId('created_by')->constrained('users');
            $t->text('reason');
            $t->timestamps();
            $t->unique(['business_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_recognitions');
        Schema::dropIfExists('funding_commitments');
    }
};
