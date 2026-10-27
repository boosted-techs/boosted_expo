<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function reference(Blueprint $t, string $column, string $table): void
    {
        $t->unsignedBigInteger($column)->nullable();
        $t->foreign(['business_id', $column])->references(['business_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('staff_people', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('staff_code', 50);
            $t->string('name', 150);
            $t->string('kind', 20)->default('employee');
            $t->json('skills')->nullable();
            $t->string('phone', 50)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'staff_code']);
            $t->unique(['business_id', 'user_id']);
            $t->foreign(['business_id', 'user_id'])->references(['business_id', 'user_id'])->on('business_memberships')->restrictOnDelete();
        });
        DB::table('business_memberships')->join('users', 'users.id', '=', 'business_memberships.user_id')->select(['business_memberships.id', 'business_id', 'user_id', 'users.name', 'business_memberships.active'])->orderBy('business_memberships.id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('staff_people')->insert(['business_id' => $row->business_id, 'user_id' => $row->user_id, 'staff_code' => 'MEM-'.$row->id, 'name' => $row->name, 'active' => $row->active, 'kind' => 'employee', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        Schema::create('staff_rotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->date('week_start');
            $t->string('name', 150);
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->timestamps();
            $t->unique(['business_id', 'id']);
            $t->unique(['business_id', 'request_key']);
        });
        Schema::create('staff_rota_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->reference($t, 'staff_rota_id', 'staff_rotas');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('location', 150);
            $t->string('required_skill', 50)->nullable();
            $t->unsignedInteger('required_people');
            $t->timestamps();
        });
        foreach (['staff_shifts', 'staff_attendances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->nullable()->change();
                $this->reference($t, 'staff_person_id', 'staff_people');
            });
            DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['staff_person_id' => DB::table('staff_people')->where('business_id', $row->business_id)->where('user_id', $row->user_id)->value('id')]);
                }
            });
        }
        Schema::table('staff_shifts', function (Blueprint $t) {
            $this->reference($t, 'staff_rota_id', 'staff_rotas');
            $t->string('required_skill', 50)->nullable();
            $t->timestamp('published_at')->nullable();
        });
        DB::table('staff_shifts')->update(['published_at' => now()]);
        Schema::table('staff_attendances', function (Blueprint $t) {
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('effective_in_at')->nullable();
            $t->timestamp('effective_out_at')->nullable();
            $t->string('review_note', 1000)->nullable();
        });
        DB::statement('DROP INDEX IF EXISTS staff_attendance_one_open_per_user');
        DB::statement('CREATE UNIQUE INDEX staff_attendance_one_open_per_user ON staff_attendances (business_id, user_id) WHERE clocked_out_at IS NULL AND effective_out_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX staff_one_open_per_person ON staff_attendances (business_id, staff_person_id) WHERE clocked_out_at IS NULL AND effective_out_at IS NULL');
        Schema::create('staff_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->reference($t, 'staff_person_id', 'staff_people');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('reason', 500);
            $t->string('status', 20)->default('approved');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('staff_breaks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->reference($t, 'staff_attendance_id', 'staff_attendances');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX staff_one_open_break ON staff_breaks (business_id, staff_attendance_id) WHERE ends_at IS NULL');
        Schema::create('staff_attendance_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->reference($t, 'staff_attendance_id', 'staff_attendances');
            $t->timestamp('proposed_in_at');
            $t->timestamp('proposed_out_at');
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('partner_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $this->reference($t, 'customer_id', 'customers');
            $this->reference($t, 'supplier_id', 'suppliers');
            $this->reference($t, 'staff_person_id', 'staff_people');
            $t->string('title', 150);
            $t->text('note');
            $t->timestamp('due_at');
            $t->string('status', 20)->default('open');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'status', 'due_at']);
        });
        foreach (['market_trips', 'order_tasks', 'transport_legs'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $this->reference($t, 'staff_person_id', 'staff_people'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_followups');
        foreach (['market_trips', 'order_tasks', 'transport_legs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['business_id', 'staff_person_id']);
                $t->dropColumn('staff_person_id');
            });
        }
        foreach (['staff_attendance_corrections', 'staff_breaks', 'staff_leaves'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP INDEX IF EXISTS staff_one_open_per_person');
        DB::statement('DROP INDEX IF EXISTS staff_attendance_one_open_per_user');
        DB::statement('CREATE UNIQUE INDEX staff_attendance_one_open_per_user ON staff_attendances (business_id, user_id) WHERE clocked_out_at IS NULL');
        Schema::table('staff_attendances', function (Blueprint $t) {
            $t->dropForeign(['recorded_by']);
            $t->dropForeign(['approved_by']);
            $t->dropColumn(['recorded_by', 'approved_at', 'approved_by', 'effective_in_at', 'effective_out_at', 'review_note']);
        });
        Schema::table('staff_shifts', function (Blueprint $t) {
            $t->dropForeign(['business_id', 'staff_rota_id']);
            $t->dropColumn(['staff_rota_id', 'required_skill', 'published_at']);
        });
        foreach (['staff_shifts', 'staff_attendances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['business_id', 'staff_person_id']);
                $t->dropColumn('staff_person_id');
            });
        }
        Schema::dropIfExists('staff_rota_requirements');
        Schema::dropIfExists('staff_rotas');
        Schema::dropIfExists('staff_people');
    }
};
