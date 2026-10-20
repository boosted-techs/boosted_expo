<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('invoice_accounting_handoffs',function(Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('commercial_invoice_id');
            $t->string('currency',3);
            $t->decimal('commercial_amount',20,2);
            $t->string('posting_status',32)->default('awaiting_accounting_policy');
            $t->timestamp('prepared_at');
            $t->json('details');
            $t->timestamps();
            $t->unique(['business_id','commercial_invoice_id']);
            $t->foreign(['business_id','commercial_invoice_id'])
                ->references(['business_id','id'])->on('commercial_invoices')->restrictOnDelete();
            $t->index(['business_id','posting_status','currency']);
        });
    }

    public function down():void
    {
        Schema::dropIfExists('invoice_accounting_handoffs');
    }
};
