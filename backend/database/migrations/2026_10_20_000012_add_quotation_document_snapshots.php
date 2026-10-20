<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('sales_quotations',function(Blueprint $t) {
            $t->longText('html_snapshot')->nullable();
            $t->string('sha256',64)->nullable();
        });
    }
    public function down():void
    {
        Schema::table('sales_quotations',function(Blueprint $t) {
            $t->dropColumn(['html_snapshot','sha256']);
        });
    }
};
