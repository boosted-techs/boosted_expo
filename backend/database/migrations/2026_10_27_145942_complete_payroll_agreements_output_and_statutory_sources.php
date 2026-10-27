<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_pay_rates', function (Blueprint $t) {
            $t->decimal('regular_daily_hours', 6, 2)->default(8);
            $t->decimal('overtime_multiplier', 6, 3)->nullable();
            $t->string('output_unit', 30)->nullable();
            $t->string('work_code', 50)->nullable();
            $t->decimal('unpaid_day_divisor', 6, 2)->nullable();
            $t->decimal('overtime_hourly_amount', 20, 2)->nullable();
            $t->date('superseded_on')->nullable();
            $t->foreignId('supersedes_rate_id')->nullable()->constrained('staff_pay_rates');
        });
        Schema::table('staff_people', function (Blueprint $t) {
            $t->string('payroll_tax_class', 30)->nullable();
            $t->boolean('nssf_applicable')->nullable();
            $t->text('payroll_classification_note')->nullable();
            $t->foreignId('payroll_classified_by')->nullable()->constrained('users');
            $t->timestamp('payroll_classified_at')->nullable();
        });
        foreach (['staff_leaves', 'staff_shifts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('pay_treatment', 20)->default('unverified');
                $t->foreignId('pay_reviewed_by')->nullable()->constrained('users');
                $t->timestamp('pay_reviewed_at')->nullable();
                $t->text('pay_review_note')->nullable();
                $t->decimal('unpaid_day_units', 8, 3)->nullable();
            });
        }
        Schema::create('payroll_statutory_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->string('name', 150);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->json('rules');
            $t->text('source_note');
            $t->string('status', 20)->default('proposed');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'status', 'starts_on', 'ends_on']);
        });
        Schema::create('staff_outputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('staff_person_id')->constrained('staff_people');
            $t->foreignId('staff_pay_rate_id')->constrained('staff_pay_rates');
            $t->foreignId('packing_job_id')->nullable()->constrained('packing_jobs');
            $t->foreignId('sales_order_id')->nullable()->constrained('sales_orders');
            $t->date('worked_on');
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 30);
            $t->string('work_code', 50);
            $t->string('external_reference', 150);
            $t->text('evidence');
            $t->string('status', 20)->default('proposed');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'staff_person_id', 'external_reference']);
            $t->index(['business_id', 'staff_person_id', 'worked_on', 'status']);
        });
        Schema::create('staff_pay_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('staff_person_id')->constrained('staff_people');
            $t->date('effective_on');
            $t->string('kind', 20);
            $t->decimal('amount', 20, 2);
            $t->boolean('taxable')->default(true);
            $t->boolean('nssf_eligible')->default(true);
            $t->string('external_reference', 150);
            $t->text('evidence');
            $t->string('status', 20)->default('proposed');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'staff_person_id', 'external_reference']);
            $t->index(['business_id', 'staff_person_id', 'effective_on', 'status']);
        });
        Schema::create('finance_payroll_input_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained('finance_payroll_runs');
            $t->string('source_type', 30);
            $t->unsignedBigInteger('source_id');
            $t->json('data_snapshot');
            $t->timestamps();
            $t->unique(['payroll_run_id', 'source_type', 'source_id']);
            $t->index(['business_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['finance_payroll_input_claims', 'staff_pay_adjustments', 'staff_outputs', 'payroll_statutory_policies'] as $name) {
            Schema::dropIfExists($name);
        }foreach (['staff_leaves', 'staff_shifts'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropConstrainedForeignId('pay_reviewed_by');
                $t->dropColumn(['pay_treatment', 'pay_reviewed_at', 'pay_review_note', 'unpaid_day_units']);
            });
        }Schema::table('staff_people', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payroll_classified_by');
            $t->dropColumn(['payroll_tax_class', 'nssf_applicable', 'payroll_classification_note', 'payroll_classified_at']);
        });
        Schema::table('staff_pay_rates', fn (Blueprint $t) => $t->dropConstrainedForeignId('supersedes_rate_id'));
        Schema::table('staff_pay_rates', fn (Blueprint $t) => $t->dropColumn(['regular_daily_hours', 'overtime_multiplier', 'output_unit', 'work_code', 'unpaid_day_divisor', 'overtime_hourly_amount', 'superseded_on']));
    }
};
