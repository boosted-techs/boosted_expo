<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('users',function(Blueprint $table){
            $table->text('mfa_secret')->nullable();
            $table->longText('mfa_recovery_codes')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->unsignedBigInteger('mfa_last_counter')->nullable();
        });
    }
    public function down():void
    {
        // Dropping MFA during rollback would silently remove the only second
        // factor and recovery codes of privileged staff. Explicitly refuse.
        throw new RuntimeException('Privileged MFA credentials cannot be silently rolled back.');
    }
};
