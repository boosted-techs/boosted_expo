<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('packing_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->unsignedBigInteger('product_id');
            $t->string('status', 20)->default('open');
            $t->date('planned_on')->nullable();
            $t->unsignedInteger('target_cartons')->nullable();
            $t->string('packing_type', 60)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'reference']);
            $t->unique(['business_id', 'id']);
            $t->foreign(['business_id', 'product_id'])
                ->references(['business_id', 'id'])->on('products')->restrictOnDelete();
            $t->index(['business_id', 'status']);
        });

        Schema::table('cartons', function (Blueprint $t) {
            $t->unsignedBigInteger('packing_job_id')->nullable();
            $t->string('grade', 50)->nullable();
            $t->string('packaging_type', 60)->nullable();
            $t->decimal('tare_weight_kg', 14, 3)->nullable();
            $t->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('packed_at')->nullable();
            $t->uuid('packing_key')->nullable();
            $t->unique(['business_id', 'packing_key']);
            $t->foreign(['business_id', 'packing_job_id'])
                ->references(['business_id', 'id'])->on('packing_jobs')->restrictOnDelete();
        });

        Schema::create('packing_waste_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('packing_job_id');
            $t->unsignedBigInteger('batch_id');
            $t->decimal('quantity', 14, 3);
            $t->string('reason', 30);
            $t->text('notes')->nullable();
            $t->uuid('idempotency_key');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'idempotency_key']);
            $t->foreign(['business_id', 'packing_job_id'])
                ->references(['business_id', 'id'])->on('packing_jobs')->restrictOnDelete();
            $t->foreign(['business_id', 'batch_id'])
                ->references(['business_id', 'id'])->on('stock_batches')->restrictOnDelete();
        });

        Schema::create('label_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->unsignedSmallInteger('width_mm')->default(100);
            $t->unsignedSmallInteger('height_mm')->default(50);
            $t->unsignedSmallInteger('dpi')->default(203);
            $t->string('qr_level', 12)->default('carton');
            $t->boolean('include_barcode')->default(true);
            $t->json('visible_fields');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','name']);
            $t->unique(['business_id','id']);
        });

        Schema::create('label_generations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('carton_id');
            $t->unsignedBigInteger('template_id');
            $t->unsignedInteger('template_version');
            $t->string('zpl_sha256', 64);
            $t->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['business_id','carton_id'])
                ->references(['business_id','id'])->on('cartons')->restrictOnDelete();
            $t->foreign(['business_id','template_id'])
                ->references(['business_id','id'])->on('label_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_generations');
        Schema::dropIfExists('label_templates');
        Schema::dropIfExists('packing_waste_entries');
        Schema::table('cartons', function (Blueprint $t) {
            $t->dropForeign(['business_id','packing_job_id']);
            $t->dropUnique(['business_id','packing_key']);
            $t->dropConstrainedForeignId('packed_by');
            $t->dropColumn([
                'packing_job_id','grade','packaging_type','tare_weight_kg',
                'packed_at','packing_key',
            ]);
        });
        Schema::dropIfExists('packing_jobs');
    }
};
