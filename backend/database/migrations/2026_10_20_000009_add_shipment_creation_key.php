<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('export_shipments', function (Blueprint $t) {
            $t->uuid('creation_key')->nullable();
            $t->unique(['business_id','creation_key']);
        });
    }
    public function down(): void
    {
        Schema::table('export_shipments', function (Blueprint $t) {
            $t->dropUnique(['business_id','creation_key']);
            $t->dropColumn('creation_key');
        });
    }
};
