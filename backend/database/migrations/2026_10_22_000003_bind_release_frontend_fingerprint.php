<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('release_acceptance_runs', function(Blueprint $table){
            // Old staging campaigns must remain readable, but cannot be used
            // to approve the stricter, build-bound Phase 4 release.
            $table->char('assets_sha256',64)->nullable();
        });
    }
    public function down():void
    {
        Schema::table('release_acceptance_runs', function(Blueprint $table){
            $table->dropColumn('assets_sha256');
        });
    }
};
