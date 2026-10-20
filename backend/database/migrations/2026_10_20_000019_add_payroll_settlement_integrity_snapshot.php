<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('finance_payroll_settlements',function(Blueprint $t) {
            $t->json('data_snapshot')->nullable();
            $t->string('sha256',64)->nullable();
        });
    }
    public function down():void
    {
        Schema::table('finance_payroll_settlements',function(Blueprint $t) {
            $t->dropColumn(['data_snapshot','sha256']);
        });
    }
};
