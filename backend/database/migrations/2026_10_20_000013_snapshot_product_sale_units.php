<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        foreach(['sales_quotation_lines','sales_order_lines'] as $table) {
            Schema::table($table,function(Blueprint $t) {
                $t->string('product_name',160)->nullable();
                $t->string('product_sku',80)->nullable();
                $t->string('unit',12)->nullable();
            });
        }
    }
    public function down():void
    {
        foreach(['sales_order_lines','sales_quotation_lines'] as $table) {
            Schema::table($table,function(Blueprint $t) {
                $t->dropColumn(['product_name','product_sku','unit']);
            });
        }
    }
};
