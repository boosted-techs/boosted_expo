<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // PostgreSQL requires a referenced composite key to be unique BEFORE
        // migration 000015 creates the finance inventory-relief foreign key.
        // Prior SQLite deployments already acquired this constraint via 000016.
        if (!Schema::hasIndex('packing_waste_entries','packing_waste_tenant_id_unique')) {
            Schema::table('packing_waste_entries',function(Blueprint $table){
                $table->unique(['business_id','id'],'packing_waste_tenant_id_unique');
            });
        }
    }
    public function down():void
    {
        // This is now a required referenced key. Never delete it while finance
        // inventory-relief rows may reference these exact tenant-scoped entries.
    }
};
