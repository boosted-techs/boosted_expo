<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('label_generations', function (Blueprint $t) {
            $t->json('template_snapshot')->nullable();
            $t->longText('zpl_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('label_generations', function (Blueprint $t) {
            $t->dropColumn(['template_snapshot','zpl_snapshot']);
        });
    }
};
