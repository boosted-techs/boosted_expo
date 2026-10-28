<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_destinations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('name_key', 160);
            $t->string('country_code', 2)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('location', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'name_key']);
            $t->unique(['business_id', 'id']);
        });
        Schema::table('sales_requests', function (Blueprint $t): void {
            $t->unsignedBigInteger('destination_id')->nullable();
            $t->foreign(['business_id', 'destination_id'])->references(['business_id', 'id'])->on('business_destinations')->restrictOnDelete();
        });
        foreach (DB::table('sales_requests')->whereNotNull('destination')->where('destination', '!=', '')->orderBy('id')->cursor() as $request) {
            $name = mb_substr(trim($request->destination), 0, 160);
            $key = mb_strtolower($name);
            $id = DB::table('business_destinations')->where('business_id', $request->business_id)->where('name_key', $key)->value('id');
            if (! $id) {
                $id = DB::table('business_destinations')->insertGetId(['business_id' => $request->business_id, 'name' => $name, 'name_key' => $key, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }DB::table('sales_requests')->where('id', $request->id)->update(['destination_id' => $id]);
        }
        Schema::create('supply_request_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('supply_request_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity', 14, 3);
            $t->timestamps();
            $t->unique(['supply_request_id', 'product_id']);
            $t->unique(['business_id', 'id']);
            $t->foreign(['business_id', 'supply_request_id'])->references(['business_id', 'id'])->on('supply_requests')->cascadeOnDelete();
            $t->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::table('supplier_quotes', function (Blueprint $t): void {
            $t->dropUnique(['supply_request_id', 'supplier_id']);
            $t->unsignedBigInteger('supply_request_item_id')->nullable();
            $t->foreign(['business_id', 'supply_request_item_id'])->references(['business_id', 'id'])->on('supply_request_items')->restrictOnDelete();
            $t->unique(['supply_request_id', 'supplier_id', 'supply_request_item_id'], 'supplier_quote_item_unique');
        });
        foreach (DB::table('supply_requests')->orderBy('id')->cursor() as $request) {
            $id = DB::table('supply_request_items')->insertGetId(['business_id' => $request->business_id, 'supply_request_id' => $request->id, 'product_id' => $request->product_id, 'quantity' => $request->quantity, 'created_at' => $request->created_at ?? now(), 'updated_at' => $request->updated_at ?? now()]);
            DB::table('supplier_quotes')->where('business_id', $request->business_id)->where('supply_request_id', $request->id)->update(['supply_request_item_id' => $id]);
        }
    }

    public function down(): void
    {
        if (DB::table('supplier_quotes')->select('supply_request_id', 'supplier_id')->groupBy('supply_request_id', 'supplier_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multi-item supplier quotations exist. Preserve these records and use a forward migration.');
        }
        Schema::table('supplier_quotes', function (Blueprint $t): void {
            $t->dropForeign(['business_id', 'supply_request_item_id']);
            $t->dropUnique('supplier_quote_item_unique');
            $t->dropColumn('supply_request_item_id');
            $t->unique(['supply_request_id', 'supplier_id']);
        });
        Schema::dropIfExists('supply_request_items');
        Schema::table('sales_requests', function (Blueprint $t): void {
            $t->dropForeign(['business_id', 'destination_id']);
            $t->dropColumn('destination_id');
        });
        Schema::dropIfExists('business_destinations');
    }
};
