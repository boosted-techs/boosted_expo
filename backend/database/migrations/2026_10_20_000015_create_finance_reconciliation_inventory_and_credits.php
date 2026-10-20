<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('finance_bank_statements',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('account_id');
            $t->string('reference',65);$t->string('currency',3);
            $t->date('period_from');$t->date('period_to');
            $t->decimal('opening_balance',20,2);$t->decimal('closing_balance',20,2);
            $t->decimal('credits',20,2);$t->decimal('debits',20,2);
            $t->string('file_sha256',64);$t->string('filename',180);
            $t->string('status',20)->default('open');
            $t->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();$t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique(['business_id','account_id','file_sha256'],'bank_file_dedupe');
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','account_id'])->references(['business_id','id'])
                ->on('finance_accounts')->restrictOnDelete();
        });
        Schema::create('finance_bank_statement_lines',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('statement_id');$t->unsignedInteger('line_number');
            $t->date('transaction_on');$t->string('direction',12);
            $t->decimal('amount',20,2);
            $t->string('bank_reference',150)->nullable();
            $t->string('description',255);$t->timestamps();
            $t->unique(['statement_id','line_number']);
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','statement_id'])->references(['business_id','id'])
                ->on('finance_bank_statements')->restrictOnDelete();
        });
        Schema::create('finance_bank_matches',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('statement_line_id');
            $t->string('source_type',20)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('status',20)->default('pending');
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->text('note')->nullable();$t->timestamps();
            $t->unique(['business_id','statement_line_id']);
            $t->unique(['business_id','source_type','source_id'],'bank_unique_document');
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','statement_line_id'])->references(['business_id','id'])
                ->on('finance_bank_statement_lines')->restrictOnDelete();
        });
        Schema::create('finance_bank_match_events',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('match_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action',20);$t->json('snapshot');
            $t->timestamp('created_at');
            $t->foreign(['business_id','match_id'])->references(['business_id','id'])
                ->on('finance_bank_matches')->restrictOnDelete();
        });
        Schema::create('finance_inventory_reliefs',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference',65);$t->string('kind',12);
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('waste_entry_id')->nullable();
            $t->string('currency',3);$t->decimal('base_amount',20,2);
            $t->json('data_snapshot');$t->string('sha256',64);
            $t->string('posting_status',20)->default('not_posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();$t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique('shipment_id');$t->unique('waste_entry_id');
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','shipment_id'])->references(['business_id','id'])
                ->on('export_shipments')->restrictOnDelete();
            $t->foreign(['business_id','waste_entry_id'])->references(['business_id','id'])
                ->on('packing_waste_entries')->restrictOnDelete();
        });
        Schema::create('finance_credit_notes',function(Blueprint $t){
            $t->id();$t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('commercial_invoice_id');
            $t->string('reference',65);$t->string('currency',3);
            $t->decimal('amount',20,2);$t->text('reason');
            $t->string('posting_status',20)->default('not_posted');
            $t->json('data_snapshot');
            $t->longText('html_snapshot')->nullable();$t->string('sha256',64)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at')->nullable();$t->timestamps();
            $t->unique(['business_id','reference']);
            $t->unique('commercial_invoice_id');
            $t->unique(['business_id','id']);
            $t->foreign(['business_id','commercial_invoice_id'])
                ->references(['business_id','id'])->on('commercial_invoices')->restrictOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('finance_credit_notes');
        Schema::dropIfExists('finance_inventory_reliefs');
        Schema::dropIfExists('finance_bank_match_events');
        Schema::dropIfExists('finance_bank_matches');
        Schema::dropIfExists('finance_bank_statement_lines');
        Schema::dropIfExists('finance_bank_statements');
    }
};
