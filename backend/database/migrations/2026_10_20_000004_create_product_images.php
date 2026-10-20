<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('original_key', 350);
            $table->string('thumbnail_key', 350);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_images');
    }
};