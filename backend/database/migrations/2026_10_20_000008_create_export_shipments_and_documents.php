<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 48);
            $t->unsignedBigInteger('customer_id');
            $t->string('status', 20)->default('draft');
            $t->string('transport_mode', 12)->default('air');
            $t->string('origin_country', 2);
            $t->string('origin_airport', 90)->nullable();
            $t->string('destination_country', 2);
            $t->string('destination_airport', 90)->nullable();
            $t->string('carrier', 150)->nullable();
            $t->string('flight_number', 60)->nullable();
            $t->string('air_waybill', 80)->nullable();
            $t->dateTime('scheduled_departure_at')->nullable();
            $t->dateTime('estimated_arrival_at')->nullable();
            $t->dateTime('actual_departure_at')->nullable();
            $t->dateTime('actual_arrival_at')->nullable();
            $t->string('incoterm', 10)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['business_id', 'reference']);
            $t->unique(['business_id', 'id']);
            $t->foreign(['business_id', 'customer_id'])
                ->references(['business_id', 'id'])->on('customers')->restrictOnDelete();
            $t->index(['business_id', 'status']);
            $t->index(['business_id', 'air_waybill']);
        });
        Schema::create('shipment_cartons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('carton_id');
            $t->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique('carton_id'); // A physical carton may be assigned to only one live shipment.
            $t->unique(['business_id', 'shipment_id', 'carton_id']);
            $t->foreign(['business_id', 'shipment_id'])
                ->references(['business_id', 'id'])->on('export_shipments')->cascadeOnDelete();
            $t->foreign(['business_id', 'carton_id'])
                ->references(['business_id', 'id'])->on('cartons')->restrictOnDelete();
        });
        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 36);
            $t->json('details')->nullable();
            $t->timestamp('created_at');
            $t->foreign(['business_id','shipment_id'])
                ->references(['business_id','id'])->on('export_shipments')->restrictOnDelete();
            $t->index(['business_id','shipment_id','created_at']);
        });
        Schema::create('export_document_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('type', 32);
            $t->string('paper_size', 8)->default('A4');
            $t->string('orientation', 16)->default('portrait');
            $t->json('visible_columns');
            $t->json('header_fields');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','name']);
            $t->unique(['business_id','id']);
        });
        Schema::create('export_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('template_id');
            $t->string('reference', 65);
            $t->string('document_type', 32);
            $t->unsignedInteger('version');
            $t->unsignedInteger('template_version');
            $t->json('data_snapshot');
            $t->longText('html_snapshot');
            $t->string('sha256', 64);
            $t->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['shipment_id','document_type','version']);
            $t->foreign(['business_id','shipment_id'])
                ->references(['business_id','id'])->on('export_shipments')->restrictOnDelete();
            $t->foreign(['business_id','template_id'])
                ->references(['business_id','id'])->on('export_document_templates')->restrictOnDelete();
            $t->index(['business_id','shipment_id','document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_documents');
        Schema::dropIfExists('export_document_templates');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_cartons');
        Schema::dropIfExists('export_shipments');
    }
};