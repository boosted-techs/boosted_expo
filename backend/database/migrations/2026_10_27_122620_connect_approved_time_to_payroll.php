<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pay_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('staff_person_id')->constrained('staff_people');
            $t->string('basis', 20);
            $t->decimal('amount', 20, 2);
            $t->string('currency', 3);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20)->default('proposed');
            $t->string('agreement_reference', 250);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'staff_person_id', 'status', 'starts_on']);
        });
        Schema::table('finance_payroll_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->change();
            $t->foreignId('staff_person_id')->nullable()->constrained('staff_people');
            $t->string('name_snapshot')->nullable();
            $t->json('source_snapshot')->nullable();
            $t->unique(['business_id', 'payroll_run_id', 'staff_person_id'], 'payroll_person_one_per_run');
        });
        Schema::create('finance_payroll_time_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained('finance_payroll_runs');
            $t->foreignId('staff_attendance_id')->constrained();
            $t->json('snapshot');
            $t->timestamps();
            $t->unique(['payroll_run_id', 'staff_attendance_id']);
            $t->index(['business_id', 'staff_attendance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payroll_time_claims');
        Schema::table('finance_payroll_lines', function (Blueprint $t) {
            $t->dropUnique('payroll_person_one_per_run');
            $t->dropConstrainedForeignId('staff_person_id');
            $t->dropColumn(['name_snapshot', 'source_snapshot']);
        });
        Schema::dropIfExists('staff_pay_rates');
    }
};
