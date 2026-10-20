<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('finance_payroll_runs',function(Blueprint $t){
            $t->dropUnique('finance_payroll_runs_business_id_period_from_period_to_unique');
            $t->unsignedInteger('revision')->default(1);
            $t->unique(['business_id','period_from','period_to','revision'],'finance_payroll_period_revision_unique');
        });
    }
    public function down():void
    {
        Schema::table('finance_payroll_runs',function(Blueprint $t){
            $t->dropUnique('finance_payroll_period_revision_unique');
            $t->dropColumn('revision');
            $t->unique(['business_id','period_from','period_to']);
        });
    }
};
