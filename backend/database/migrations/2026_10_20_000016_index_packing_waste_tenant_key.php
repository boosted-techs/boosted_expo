<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        // Enables the strict (business_id, waste_entry_id) foreign key in the
        // additive finance inventory-relief table on SQLite and PostgreSQL.
        // An earlier, additive compatibility migration now creates this
        // constraint BEFORE the finance foreign key on PostgreSQL. Existing
        // SQLite installations that ran this original migration keep it.
        if(!Schema::hasIndex('packing_waste_entries','packing_waste_tenant_id_unique')) {
            Schema::table('packing_waste_entries',function(Blueprint $t){
                $t->unique(['business_id','id'],'packing_waste_tenant_id_unique');
            });
        }
    }

    public function down():void
    {
        // Preserve this referenced key; a later finance inventory-relief
        // foreign key depends on it and must not be invalidated by rollback.
    }
};
