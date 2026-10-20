<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->string('country_code', 2)->default('UG');
            $t->string('base_currency', 3)->default('UGX');
            $t->string('timezone')->default('Africa/Kampala');
            $t->enum('default_qr_level', ['product', 'batch', 'carton'])->default('batch');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('business_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('employee');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'user_id']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('sku');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('base_unit', 12)->default('kg');
            $t->string('thumbnail_key')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'sku']);
            $t->unique(['business_id', 'id']);
        });
        Schema::create('stock_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->string('batch_code');
            $t->string('source_reference')->nullable();
            $t->date('received_on')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'batch_code']);
            $t->unique(['business_id', 'id']);
            $t->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::create('cartons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('carton_code');
            $t->decimal('net_weight_kg', 14, 3)->nullable();
            $t->decimal('gross_weight_kg', 14, 3)->nullable();
            $t->string('status')->default('packing');
            $t->timestamps();
            $t->unique(['business_id', 'carton_code']);
            $t->unique(['business_id', 'id']);
        });
        Schema::create('carton_contents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('carton_id');
            $t->unsignedBigInteger('batch_id');
            $t->decimal('quantity', 14, 3);
            $t->timestamps();
            $t->foreign(['business_id', 'carton_id'])->references(['business_id', 'id'])->on('cartons')->cascadeOnDelete();
            $t->foreign(['business_id', 'batch_id'])->references(['business_id', 'id'])->on('stock_batches')->restrictOnDelete();
        });
        Schema::create('qr_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->enum('level', ['product', 'batch', 'carton']);
            $t->unsignedBigInteger('record_id');
            $t->uuid('public_token')->unique();
            $t->boolean('public_enabled')->default(false);
            $t->timestamps();
            $t->unique(['business_id', 'level', 'record_id']);
            $t->index(['business_id', 'level']);
        });
    }
    public function down(): void {
        foreach (['qr_identities', 'carton_contents', 'cartons', 'stock_batches', 'products', 'business_memberships', 'businesses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
