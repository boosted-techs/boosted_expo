<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('catalogue_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('rows');
            $table->unsignedInteger('row_count');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'user_id', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('catalogue_imports');
    }
};