<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancePayment;
use App\Models\FinanceShipmentCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code='ONE'):array
    {
        $business=Business::create([
            'code'=>$code,'name'=>$code.' Export Company','country_code'=>'UG',
            'base_currency'=>'UGX','timezone'=>'Africa/Kampala','default_qr_level'=>'carton',
        ]);
        $owner=User::factory()->create();
        $accountant=User::factory()->create();
        foreach([[$owner,'owner'],[$accountant,'accountant']] as [$actor,$role]) {
            BusinessMembership::create(['business_id'=>$business->id,'user_id'=>$actor->id,
                'role'=>$role,'active'=>true]);
        }
        $this->actingAs($owner)->withSession(['active_business_id'=>$business->id])
            ->post(route('finance.setup'))->assertRedirect();
        $bank=FinanceAccount::query()->where('business_id',$business->id)
            ->where('system_key','bank')->firstOrFail();
        $customer=Customer::create([
            'business_id'=>$business->id,'customer_code'=>'BUY-001','name'=>'Export Buyer',
            'country_code'=>'AE','currency'=>'USD',
        ]);
        $shipment=ExportShipment::create([
            'business_id'=>$business->id,'reference'=>'SHIP-'.Str::ulid(),
            'status'=>'dispatched','transport_mode'=>'air','origin_country'=>'UG',
            'destination_country'=>'AE','created_by'=>$owner->id,'customer_id'=>$customer->id,
        ]);
        $payment=FinancePayment::create([
            'business_id'=>$business->id,'reference'=>'PAY-'.Str::ulid(),
            'direction'=>'in','cash_account_id'=>$bank->id,'currency'=>'USD','amount'=>'60',
            'payment_on'=>now()->toDateString(),'external_reference'=>'BANK-RX-100',
            'status'=>'posted','idempotency_key'=>(string)Str::uuid(),
            'created_by'=>$accountant->id,'posted_at'=>now(),
        ]);
        $expense=FinanceShipmentCost::create([
            'business_id'=>$business->id,'reference'=>'COST-'.Str::ulid(),
            'shipment_id'=>$shipment->id,'cash_account_id'=>$bank->id,
            'category'=>'handling','description'=>'Airport payment',
            'currency'=>'USD','amount'=>'20','paid_on'=>now()->toDateString(),
            'external_reference'=>'BANK-DB-200','status'=>'posted',
            'created_by'=>$accountant->id,'posted_at'=>now(),
        ]);
        return compact('business','owner','accountant','bank','payment','expense');
    }

    private function import(array $ctx,string $fileName='bank.csv',
        string $credit='60.00',string $debit='20.00',string $opening='100.00',string $closing='140.00'):FinanceBankStatement
    {
        $today=now()->toDateString();
        $csv="date,direction,amount,reference,description\n".
            "{$today},credit,{$credit},BANK-RX-100,Customer deposit\n".
            "{$today},debit,{$debit},BANK-DB-200,Airport handling payment\n";
        $file=UploadedFile::fake()->createWithContent($fileName,$csv);
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.import'),[
            'currency'=>'USD','period_from'=>$today,'period_to'=>$today,
            'opening_balance'=>$opening,'closing_balance'=>$closing,'file'=>$file,
        ])->assertRedirect()->assertSessionHasNoErrors();
        return FinanceBankStatement::query()->where('business_id',$ctx['business']->id)
            ->latest('id')->firstOrFail();
    }

    public function test_csv_balances_matching_review_and_closure_preserve_original_entries():void
    {
        $ctx=$this->context();
        $stmt=$this->import($ctx);
        $this->assertSame('open',$stmt->status);
        $this->assertDatabaseCount('finance_bank_statement_lines',2);
        $this->get(route('finance.bank.show',$stmt->id))->assertOk()->assertSee('Customer deposit');
        $lines=$stmt->lines()->orderBy('line_number')->get();
        $this->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$lines[0]->id,
        ]),[
            'candidate'=>'payment:'.$ctx['payment']->id,
            'note'=>'Matched bank reference BANK-RX-100 to posted customer receipt',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $first=FinanceBankMatch::where('statement_line_id',$lines[0]->id)->firstOrFail();
        $this->post(route('finance.bank.confirm',$first->id),[
            'note'=>'Attempting to review own bank match',
        ])->assertForbidden();
        $this->actingAs($ctx['owner'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.confirm',$first->id),[
            'note'=>'I have reviewed the independent bank statement and receipt reference.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed',$first->fresh()->status);
        $this->post(route('finance.bank.complete',$stmt->id))
            ->assertStatus(422);

        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$lines[1]->id,
        ]),[
            'candidate'=>'shipment_cost:'.$ctx['expense']->id,
            'note'=>'Matched payment reference BANK-DB-200 to posted cost voucher',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $second=FinanceBankMatch::where('statement_line_id',$lines[1]->id)->firstOrFail();
        $this->actingAs($ctx['owner'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.confirm',$second->id),[
            'note'=>'Expense and debit were independently checked.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.bank.complete',$stmt->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reconciled',$stmt->fresh()->status);
        $this->get(route('finance.bank.show',$stmt->id))->assertOk()->assertSee('Reconciled');
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertDatabaseCount('finance_bank_match_events',4);
        $this->assertDatabaseCount('finance_bank_matches',2);
        $this->assertSame('posted',$ctx['payment']->fresh()->status);
    }

    public function test_import_rejects_invalid_csv_balances_duplicate_files_and_cross_business_access():void
    {
        $ctx=$this->context();
        $stmt=$this->import($ctx);
        $today=now()->toDateString();
        $duplicate=UploadedFile::fake()->createWithContent('duplicate.csv',
            "date,direction,amount,reference,description\n".
            "{$today},credit,60.00,BANK-RX-100,Customer deposit\n".
            "{$today},debit,20.00,BANK-DB-200,Airport handling payment\n");
        $this->post(route('finance.bank.import'),[
            'currency'=>'USD','period_from'=>$today,'period_to'=>$today,
            'opening_balance'=>'100.00','closing_balance'=>'140.00','file'=>$duplicate,
        ])->assertSessionHasErrors('file');
        $bad=UploadedFile::fake()->createWithContent('bad.csv',
            "date,direction,amount,reference,description\n".
            "{$today},credit,60.00,BANK-RX-100,Customer deposit\n".
            "{$today},debit,20.00,BANK-DB-200,Airport handling payment\n");
        $this->post(route('finance.bank.import'),[
            'currency'=>'USD','period_from'=>$today,'period_to'=>$today,
            'opening_balance'=>'100.00','closing_balance'=>'139.99','file'=>$bad,
        ])->assertSessionHasErrors('closing_balance');
        $this->assertDatabaseCount('finance_bank_statements',1);

        $other=$this->context('TWO');
        $this->actingAs($other['accountant'])->withSession([
            'active_business_id'=>$other['business']->id,
        ])->get(route('finance.bank.show',$stmt->id))->assertNotFound();
        $line=$stmt->lines()->firstOrFail();
        $this->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$line->id,
        ]),[
            'candidate'=>'payment:'.$ctx['payment']->id,
            'note'=>'Trying to link another company bank file',
        ])->assertNotFound();
        $viewer=User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$ctx['business']->id,'user_id'=>$viewer->id,
            'role'=>'viewer','active'=>true,
        ]);
        $this->actingAs($viewer)->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->get(route('finance.bank.index'))->assertForbidden();
    }

    public function test_wrong_type_or_amount_is_rejected_and_rejected_match_can_be_corrected():void
    {
        $ctx=$this->context();
        $stmt=$this->import($ctx);
        $line=$stmt->lines()->orderBy('line_number')->firstOrFail();
        $this->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$line->id,
        ]),[
            'candidate'=>'shipment_cost:'.$ctx['expense']->id,
            'note'=>'Trying an expense against a bank credit',
        ])->assertSessionHasErrors('source');
        $this->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$line->id,
        ]),[
            'candidate'=>'payment:'.$ctx['payment']->id,
            'note'=>'Customer bank transfer verified against receipt',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $match=FinanceBankMatch::firstOrFail();
        $this->actingAs($ctx['owner'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.reject',$match->id),[
            'note'=>'Insufficient evidence supplied for reference match',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($match->fresh()->source_id);
        $this->actingAs($ctx['accountant'])->withSession([
            'active_business_id'=>$ctx['business']->id,
        ])->post(route('finance.bank.match',[
            'statement'=>$stmt->id,'line'=>$line->id,
        ]),[
            'candidate'=>'payment:'.$ctx['payment']->id,
            'note'=>'Updated buyer transfer reference now verified',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending',$match->fresh()->status);
        $this->assertSame($ctx['payment']->id,$match->fresh()->source_id);
        $this->assertDatabaseCount('finance_bank_match_events',3);
    }
}
