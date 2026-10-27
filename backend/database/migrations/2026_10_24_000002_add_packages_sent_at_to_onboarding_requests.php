<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table) {
            $table->timestamp('packages_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table) {
            $table->dropColumn('packages_sent_at');
        });
    }
};
