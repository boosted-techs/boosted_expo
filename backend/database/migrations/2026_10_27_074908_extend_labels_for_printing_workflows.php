<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('label_media_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('printer_model', 100);
            $t->string('protocol', 20)->default('zpl');
            $t->unsignedSmallInteger('dpi')->default(203);
            $t->decimal('width_mm', 7, 2);
            $t->decimal('height_mm', 7, 2);
            $t->decimal('margin_mm', 5, 2)->default(2);
            $t->decimal('offset_x_mm', 5, 2)->default(0);
            $t->decimal('offset_y_mm', 5, 2)->default(0);
            $t->string('media_type', 20)->default('gap');
            $t->unsignedSmallInteger('orientation')->default(0);
            $t->text('test_evidence')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->foreignId('tested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'name']);
            $t->unique(['business_id', 'id']);
        });
        Schema::create('label_print_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_key');
            $t->string('payload_sha256', 64);
            $t->unsignedSmallInteger('copies')->default(1);
            $t->unsignedSmallInteger('carton_count');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
            $t->unique(['business_id', 'id']);
        });
        Schema::table('label_templates', function (Blueprint $t) {
            $t->decimal('width_mm', 7, 2)->default(100)->change();
            $t->decimal('height_mm', 7, 2)->default(50)->change();
            $t->unsignedBigInteger('media_profile_id')->nullable();
            $t->json('layout')->nullable();
            $t->json('required_fields')->nullable();
            $t->string('qr_visibility', 12)->default('public');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            foreach (['media_profile_id' => 'label_media_profiles', 'customer_id' => 'customers', 'product_id' => 'products'] as $column => $table) {
                $t->foreign(['business_id', $column])->references(['business_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::table('label_generations', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->string('scan_token', 26)->nullable()->unique();
            $t->json('source_snapshot')->nullable();
            $t->string('source_sha256', 64)->nullable();
            $t->longText('artwork_png')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreign(['business_id', 'batch_id'])->references(['business_id', 'id'])->on('label_print_batches')->restrictOnDelete();
            $t->unique(['business_id', 'id']);
        });
        Schema::table('label_print_agents', function (Blueprint $t) {
            $t->unsignedBigInteger('media_profile_id')->nullable();
            $t->foreign(['business_id', 'media_profile_id'])->references(['business_id', 'id'])->on('label_media_profiles')->restrictOnDelete();
        });
        Schema::table('label_print_jobs', function (Blueprint $t) {
            $t->unsignedSmallInteger('copies')->default(1);
            $t->unsignedBigInteger('parent_job_id')->nullable();
            $t->text('operator_reason')->nullable();
            $t->foreign(['business_id', 'parent_job_id'])->references(['business_id', 'id'])->on('label_print_jobs')->restrictOnDelete();
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->boolean('label_verification_required')->default(false);
        });
        Schema::create('label_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('generation_id')->nullable();
            $t->unsignedBigInteger('job_id')->nullable();
            $t->string('action', 40);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['business_id', 'generation_id'])->references(['business_id', 'id'])->on('label_generations')->restrictOnDelete();
            $t->foreign(['business_id', 'job_id'])->references(['business_id', 'id'])->on('label_print_jobs')->restrictOnDelete();
            $t->index(['business_id', 'generation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_events');
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('label_verification_required'));
        Schema::table('label_print_jobs', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'parent_job_id']);
            $t->dropColumn(['copies', 'parent_job_id', 'operator_reason']);
        });
        Schema::table('label_print_agents', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'media_profile_id']);
            $t->dropColumn('media_profile_id');
        });
        Schema::table('label_generations', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'batch_id']);
            $t->dropUnique(['business_id', 'id']);
            $t->dropConstrainedForeignId('verified_by');
            $t->dropUnique(['scan_token']);
            $t->dropColumn(['batch_id', 'scan_token', 'source_snapshot', 'source_sha256', 'artwork_png', 'verified_at']);
        });
        Schema::table('label_templates', function (Blueprint $t) {
            foreach (['media_profile_id', 'customer_id', 'product_id'] as $column) {
                $t->dropForeign(['business_id', $column]);
            }
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['media_profile_id', 'layout', 'required_fields', 'qr_visibility', 'customer_id', 'product_id', 'is_default', 'approved_at']);
        });
        Schema::dropIfExists('label_print_batches');
        Schema::dropIfExists('label_media_profiles');
    }
};
