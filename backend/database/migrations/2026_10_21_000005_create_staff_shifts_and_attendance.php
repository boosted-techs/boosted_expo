<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_shifts',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('location',150)->nullable();
            $t->text('notes')->nullable();
            $t->string('status',20)->default('scheduled');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('cancel_reason',500)->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->index(['business_id','user_id','starts_at']);
            $t->foreign(['business_id','user_id'])->references(['business_id','user_id'])
                ->on('business_memberships')->restrictOnDelete();
        });
        Schema::create('staff_attendances',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('staff_shift_id')->nullable();
            $t->timestamp('clocked_in_at');
            $t->timestamp('clocked_out_at')->nullable();
            $t->text('work_note')->nullable();
            $t->timestamps();
            $t->unique(['business_id','id']);
            $t->unique(['business_id','staff_shift_id']);
            $t->index(['business_id','user_id','clocked_in_at']);
            $t->foreign(['business_id','user_id'])->references(['business_id','user_id'])
                ->on('business_memberships')->restrictOnDelete();
            $t->foreign(['business_id','staff_shift_id'])->references(['business_id','id'])
                ->on('staff_shifts')->restrictOnDelete();
        });
        // Supported by SQLite and PostgreSQL. Guards concurrent clock-ins even
        // if two HTTP workers race before the business-scoped transaction lock.
        DB::statement('CREATE UNIQUE INDEX staff_attendance_one_open_per_user ON staff_attendances (business_id, user_id) WHERE clocked_out_at IS NULL');
        Schema::create('staff_attendance_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('attendance_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',30);
            $t->string('detail',500)->nullable();
            $t->timestamp('created_at');
            $t->foreign(['business_id','attendance_id'])->references(['business_id','id'])
                ->on('staff_attendances')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_events');
        Schema::dropIfExists('staff_attendances');
        Schema::dropIfExists('staff_shifts');
    }
};
