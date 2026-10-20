<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        // Enables the strict (business_id, waste_entry_id) foreign key in the
        // additive finance inventory-relief table on SQLite and PostgreSQL.
        Schema::table('packing_waste_entries',function(Blueprint $t){
            $t->unique(['business_id','id'],'packing_waste_tenant_id_unique');
        });
    }

    public function down():void
    {
        Schema::table('packing_waste_entries',function(Blueprint $t){
            $t->dropUnique('packing_waste_tenant_id_unique');
        });
    }
};
