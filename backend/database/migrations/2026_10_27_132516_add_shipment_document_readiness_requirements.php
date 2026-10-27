<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_operating_policies', fn (Blueprint $t) => $t->json('required_export_documents')->nullable());
        Schema::create('shipment_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('shipment_id')->constrained('export_shipments');
            $t->string('document_kind', 50);
            $t->boolean('file_required')->default(true);
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->date('due_on')->nullable();
            $t->string('status', 20)->default('required');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('waived_by')->nullable()->constrained('users');
            $t->timestamp('waived_at')->nullable();
            $t->text('waiver_reason')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'shipment_id', 'document_kind']);
        });
        Schema::table('export_clearance_checks', function (Blueprint $t) {
            $t->char('manifest_hash', 64)->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name', 150)->nullable();
            $t->string('file_mime', 80)->nullable();
            $t->char('file_sha256', 64)->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users');
            $t->timestamp('revoked_at')->nullable();
            $t->text('revocation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('export_clearance_checks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('revoked_by');
            $t->dropColumn(['manifest_hash', 'file_path', 'file_name', 'file_mime', 'file_sha256', 'revoked_at', 'revocation_reason']);
        });
        Schema::dropIfExists('shipment_requirements');
        Schema::table('business_operating_policies', fn (Blueprint $t) => $t->dropColumn('required_export_documents'));
    }
};
