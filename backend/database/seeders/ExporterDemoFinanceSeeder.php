<?php

namespace Database\Seeders;

use App\Models\FinanceOpeningItem;
use App\Models\FinanceWorkflow;
use App\Models\MaterialLot;
use App\Models\StockBatch;
use App\Services\AssetRegister;
use App\Services\FinanceCutovers;
use App\Services\FinanceDocuments;
use App\Services\FinanceForeignBankOpenings;
use App\Services\FinanceFunding;
use App\Services\FinanceFxRevaluations;
use App\Services\FinanceMonetaryRevaluations;
use App\Services\FinanceMoneyCorrections;
use App\Services\FinanceOpeningBalances;
use App\Services\FinanceOpeningItems;
use App\Services\FinanceOperations;
use App\Services\FinanceProductionCosts;
use App\Services\FinanceRates;
use App\Services\FinanceRegisterPostings;
use App\Services\FinanceSetup;
use App\Services\FinanceTradeControls;
use App\Services\FinanceTreasury;
use App\Services\LoanAgreementAmendments;
use App\Services\LoanRegister;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;

class ExporterDemoFinanceSeeder extends Seeder
{
    public function opening(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $date = $d->date(30);
        $setup = app(FinanceSetup::class);
        $opening = app(FinanceOpeningBalances::class)->prepare($b, ['effective_on' => $date, 'reason' => $d->note('Historical UGX bank and opening equity.'), 'lines' => [['account_id' => $d->get('ugx_bank')->account_id, 'debit' => '5000000', 'credit' => '0'], ['account_id' => $setup->account($b->id, 'opening_equity')->id, 'debit' => '0', 'credit' => '5000000']]], $d->id('accountant'));
        $d->post('opening_balance', $opening, $date);
        $foreign = app(FinanceForeignBankOpenings::class)->prepare($b, ['bank_profile_id' => $d->get('usd_bank')->id, 'currency' => 'USD', 'foreign_amount' => '1000', 'effective_on' => $date, 'exchange_rate_id' => $d->get('usd_rate')->id, 'reason' => $d->note('Historical USD account: 1,000 USD and UGX 3,700,000.'), 'bank_evidence' => $d->note('Fictional opening bank statement for training.')], $d->id('accountant'));
        $d->post('foreign_bank_opening', $foreign, $date, $d->get('usd_rate')->id);
        $d->put('foreign_opening', $foreign);
        $row = fn (array $r) => [...array_fill_keys(FinanceCutovers::COLUMNS, ''), 'notes' => $d->note('Synthetic signed opening position and physical count.'), ...$r];
        $rows = [$row(['type' => 'stock', 'reference' => 'DEMO-OPENING-AVOCADO', 'item_code' => 'DEMO-AVO', 'currency' => 'UGX', 'amount' => '400000', 'quantity' => '200', 'location' => $d->get('location')->name]), $row(['type' => 'cash', 'reference' => 'DEMO-OPENING-CASH', 'item_code' => 'cash', 'currency' => 'UGX', 'amount' => '20000000']), $row(['type' => 'customer_debt', 'reference' => 'DEMO-OLD-CUSTOMER', 'party_code' => 'DEMO-LOCAL', 'currency' => 'UGX', 'amount' => '250000', 'due_on' => $d->date(2)]), $row(['type' => 'supplier_debt', 'reference' => 'DEMO-OLD-SUPPLIER', 'party_code' => 'DEMO-GROWER', 'currency' => 'UGX', 'amount' => '150000', 'due_on' => $d->date(-2)]), $row(['type' => 'staff_advance', 'reference' => 'DEMO-OLD-STAFF', 'party_code' => 'DEMO-BUYER', 'currency' => 'UGX', 'amount' => '100000', 'due_on' => $d->date(-1)]), $row(['type' => 'supplier_advance', 'reference' => 'DEMO-OLD-ADVANCE', 'party_code' => 'DEMO-GROWER', 'currency' => 'UGX', 'amount' => '50000', 'due_on' => $d->date(-2)]), $row(['type' => 'customer_deposit', 'reference' => 'DEMO-OLD-DEPOSIT', 'party_code' => 'DEMO-LOCAL', 'currency' => 'UGX', 'amount' => '50000', 'due_on' => $d->date(-4)]), $row(['type' => 'order', 'reference' => 'DEMO-PENDING-PINE-LINE', 'order_reference' => 'DEMO-PENDING-PINE', 'party_code' => 'DEMO-LOCAL', 'item_code' => 'DEMO-PINE', 'quantity' => '20', 'due_on' => $d->date(-2)]), $row(['type' => 'purchase_supplier', 'reference' => 'DEMO-PENDING-PINE-BUY', 'party_code' => 'DEMO-GROWER', 'item_code' => 'DEMO-PINE', 'quantity' => '20', 'currency' => 'UGX', 'amount' => '2500', 'due_on' => $d->date(-1), 'order_reference' => 'DEMO-PENDING-PINE'])];
        $controls = ['cash' => '20000000', 'inventory' => '400000', 'receivable' => '250000', 'payable' => '-150000', 'staff_advances' => '100000', 'supplier_advances' => '50000', 'customer_deposits' => '-50000', $d->get('ugx_bank')->account->system_key => '5000000', $d->get('usd_bank')->account->system_key => '3700000', 'opening_equity' => '-29300000'];
        foreach ($controls as $key => $value) {
            $rows[] = $row(['type' => 'control', 'reference' => 'DEMO-CONTROL-'.$key, 'item_code' => $key, 'amount' => $value]);
        }
        $f = fopen('php://temp', 'w+');
        fputcsv($f, FinanceCutovers::COLUMNS, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($f, array_values($r), ',', '"', '');
        }rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);
        $path = $d->file('cutover-evidence', 'demo-opening.csv', $csv);
        $sha = hash('sha256', $csv);
        $cut = app(FinanceCutovers::class)->prepare($b, ['effective_on' => $date, 'external_reference' => 'DEMO-OPENING-REGISTER', 'reason' => $d->note('Illustrative reconciled opening stock, cash, debt and advance records.'), 'request_key' => $d->key(), 'file_sha256' => $sha], $rows, $d->id('accountant'));
        $cut->update(['source_file_path' => $path, 'source_file_sha256' => $sha]);
        $d->post('finance_workflow', FinanceWorkflow::findOrFail($cut->workflow_id), $date);
        app(FinanceCutovers::class)->accept($b, $cut->fresh(), $d->note('Independent training review of every opening source and control.'), $d->id('owner'));
        $d->put('cutover', $cut);
        $d->put('opening_batch', StockBatch::where('business_id', $b->id)->where('batch_code', 'DEMO-OPENING-AVOCADO')->firstOrFail());
    }

    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $a = $d->id('accountant');
        $owner = $d->id('owner');
        $flow = app(FinanceTreasury::class)->prepare($b, 'owner_contribution', $d->input(['currency' => 'UGX', 'amount' => '3000000', 'account_key' => 'cash']), $a);
        $d->post('finance_workflow', $flow);
        $flow = app(FinanceTreasury::class)->prepare($b, 'owner_drawing', $d->input(['currency' => 'UGX', 'amount' => '100000', 'account_key' => 'cash']), $a);
        $d->post('finance_workflow', $flow);
        $d->put('drawing', $flow);
        $cash = app(FinanceTreasury::class)->createCustody($b, ['name' => 'DEMO cash custodian', 'currency' => 'UGX', 'staff_person_id' => $d->get('staff_accountant')->id, 'authorised_float' => '30000000', 'use_general_cash' => true], $owner);
        $d->put('cash_custody', $cash);
        $expense = app(FinanceOperations::class)->prepare($b, ['kind' => 'paid_expense', 'currency' => 'UGX', 'amount' => '80000', 'event_on' => $d->date(), 'description' => $d->note('Actual example packhouse transport expense.'), 'category' => 'transport', 'cash_account_key' => 'cash', 'external_reference' => 'DEMO-TRANSPORT-RECEIPT', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $expense);
        $d->put('expense', $expense);
        $advance = app(FinanceOperations::class)->prepare($b, ['kind' => 'staff_advance', 'staff_person_id' => $d->get('staff_buyer')->id, 'currency' => 'UGX', 'amount' => '200000', 'event_on' => $d->date(), 'description' => $d->note('Market buyer cash issued for produce.'), 'cash_account_key' => 'cash', 'external_reference' => 'DEMO-MARKET-ADVANCE', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $advance);
        $d->put('staff_advance', $advance);
        $expense = app(FinanceOperations::class)->prepare($b, ['kind' => 'staff_expense', 'parent_id' => $advance->id, 'currency' => 'UGX', 'amount' => '20000', 'event_on' => $d->date(), 'description' => $d->note('Transport accounted against the issued market advance.'), 'category' => 'transport', 'external_reference' => 'DEMO-ADVANCE-EXPENSE', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $expense);
        $claim = app(FinanceOperations::class)->prepare($b, ['kind' => 'expense', 'staff_person_id' => $d->get('staff_buyer')->id, 'currency' => 'UGX', 'amount' => '40000', 'event_on' => $d->date(), 'description' => $d->note('Staff-paid handling claim awaiting reimbursement.'), 'category' => 'handling', 'due_on' => $d->date(-3), 'external_reference' => 'DEMO-STAFF-CLAIM', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $claim);
        $d->put('staff_claim', $claim);
        $item = FinanceOpeningItem::where('business_id', $b->id)->where('kind', 'staff_advance')->firstOrFail();
        $flow = app(FinanceOpeningItems::class)->prepare($b, $item, 'opening_item_apply', $d->input(['amount' => '20000', 'target_type' => 'expense', 'target_id' => $claim->id]), $a);
        $d->post('finance_workflow', $flow);
        $deposit = app(FinanceOperations::class)->prepare($b, ['kind' => 'customer_deposit', 'customer_id' => $d->get('customer')->id, 'currency' => 'USD', 'amount' => '100', 'event_on' => $d->date(), 'exchange_rate_id' => $d->get('usd_rate')->id, 'bank_profile_id' => $d->get('usd_bank')->id, 'description' => $d->note('Buyer deposit retained separately until applied.'), 'external_reference' => 'DEMO-BUYER-DEPOSIT', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $deposit, $d->date(), $d->get('usd_rate')->id);
        $d->put('deposit', $deposit);
        $recurring = app(FinanceTreasury::class)->recurring($b, ['name' => 'DEMO packhouse rent', 'currency' => 'UGX', 'amount' => '350000', 'category' => 'rent', 'interval_months' => 1, 'terms' => $d->note('Monthly rent agreement for training.'), 'next_due_on' => $d->date(), 'supplier_id' => $d->get('vendor')->id, 'evidence' => $d->note('Illustrative monthly lease, not a real agreement.')], $a);
        $d->put('recurring', $recurring);
        $bill = app(FinanceTreasury::class)->recognise($b, $recurring, $d->input(['due_on' => $d->date()]), $a);
        $d->post('money_operation', $bill);
        $d->put('rent', $bill);
        $plan = app(FinanceFunding::class)->propose($b, ['kind' => 'operating', 'currency' => 'UGX', 'amount' => '500000', 'due_on' => $d->date(-4), 'purpose' => 'DEMO export inspection and handling budget', 'evidence' => $d->note('Reviewed planning commitment; no payment has occurred.'), 'request_key' => $d->key()], $a);
        app(FinanceFunding::class)->review($b, $plan, true, $d->note('Independent review of the planning assumption.'), $owner);
        $d->put('funding', $plan);
        $this->registersAndCosts($d);
    }

    private function registersAndCosts(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $a = $d->id('accountant');
        $owner = $d->id('owner');
        $asset = app(AssetRegister::class)->create($b, ['asset_code' => 'DEMO-SCALE', 'name' => 'DEMO packhouse platform scale', 'category' => 'equipment', 'ownership' => 'owned', 'serial_number' => 'DEMO-SCALE-001', 'purchase_reference' => 'DEMO-EQUIPMENT-INVOICE', 'location' => 'Packhouse', 'condition' => 'good', 'useful_life_months' => 60, 'residual_base' => '0', 'depreciation_start_on' => now('Africa/Kampala')->startOfMonth()->toDateString(), 'maintenance_due_on' => $d->date(-30), 'notes' => $d->note('Illustrative owned equipment and custody.')], $a);
        $d->put('asset', $asset);
        $entry = app(FinanceRegisterPostings::class)->prepare($b, 'asset_purchase', $d->input(['asset_id' => $asset->id, 'currency' => 'UGX', 'amount' => '1500000', 'paid' => true, 'cash_account_key' => 'cash']), $a);
        $d->post('register_entry', $entry);
        app(AssetRegister::class)->activity($b, $asset, ['kind' => 'assign', 'staff_person_id' => $d->get('staff_warehouse')->id, 'location' => 'DEMO Kampala packhouse', 'event_on' => $d->date(), 'reference' => 'DEMO-SCALE-HANDOVER', 'note' => $d->note('Scale assigned to receiving supervisor.'), 'request_key' => $d->key()], $owner);
        $loan = app(LoanRegister::class)->create($b, ['reference' => 'DEMO-WORKING-CAPITAL', 'lender_name' => 'DEMO Training Lender', 'lender_kind' => 'bank', 'currency' => 'UGX', 'limit_amount' => '5000000', 'revolving' => false, 'starts_on' => $d->date(30), 'matures_on' => $d->date(-180), 'agreement_reference' => 'DEMO-LOAN-AGREEMENT', 'terms' => $d->note('Borrowing example; no lender account or external transfer.')], $a);
        $d->put('loan', $loan);
        $entry = app(FinanceRegisterPostings::class)->prepare($b, 'loan_drawdown', $d->input(['loan_id' => $loan->id, 'currency' => 'UGX', 'amount' => '2000000', 'cash_account_key' => 'cash']), $a);
        $d->post('register_entry', $entry);
        $instalment = app(LoanRegister::class)->schedule($b, $loan, ['reference' => 'DEMO-INSTALMENT-01', 'due_on' => $d->date(-14), 'principal' => '200000', 'interest' => '20000', 'fees' => '0', 'notes' => $d->note('Next repayment responsibility.')], $a);
        $d->put('loan_instalment', $instalment);
        app(LoanAgreementAmendments::class)->propose($b, $loan, $d->input(['limit_amount' => '6000000', 'revolving' => false, 'effective_on' => $d->date(), 'matures_on' => $d->date(-210), 'agreement_reference' => 'DEMO-PROPOSED-EXTENSION', 'terms' => $d->note('Illustrative loan extension awaiting review.')]), $a);
        $cost = app(FinanceDocuments::class)->shipmentCost($b, ['shipment_id' => $d->get('shipment')->id, 'category' => 'transport', 'description' => $d->note('Airport truck and cold-chain transport cost.'), 'currency' => 'UGX', 'amount' => '75000', 'cash_account_key' => 'cash', 'paid_on' => $d->date(), 'external_reference' => 'DEMO-TRUCK-RECEIPT', 'idempotency_key' => $d->key()], $a);
        $d->post('shipment_cost', $cost);
        $d->put('shipment_cost', $cost);
        $payment = app(FinanceDocuments::class)->payment($b, ['direction' => 'out', 'supplier_bill_id' => $d->get('bill_market')->id, 'amount' => '30000', 'payment_on' => $d->date(), 'cash_account_key' => 'cash', 'external_reference' => 'DEMO-MARKET-PART-PAYMENT', 'notes' => $d->note('Partial supplier payment; balance remains payable.'), 'idempotency_key' => $d->key()], $a);
        $d->post('payment', $payment);
        $d->put('supplier_payment', $payment);
        $materialExpense = app(FinanceOperations::class)->prepare($b, ['kind' => 'paid_expense', 'currency' => 'UGX', 'amount' => '100000', 'event_on' => $d->date(), 'description' => $d->note('Packing cartons purchased for stock.'), 'category' => 'packing_material', 'cash_account_key' => 'cash', 'external_reference' => 'DEMO-BOX-PURCHASE', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $materialExpense);
        $flow = app(FinanceProductionCosts::class)->prepare($b, 'material_receipt', $d->input(['source_type' => 'expense', 'source_id' => $materialExpense->id, 'source_account' => 'general_expense', 'base_amount' => '100000', 'quantity' => '100', 'name' => 'DEMO 20 kg export boxes', 'unit' => 'each', 'location' => 'Packing store']), $a);
        $d->post('finance_workflow', $flow);
        $lot = MaterialLot::where('business_id', $b->id)->firstOrFail();
        $flow = app(FinanceProductionCosts::class)->prepare($b, 'material_issue', $d->input(['material_lot_id' => $lot->id, 'batch_id' => $d->get('opening_batch')->id, 'quantity' => '2']), $a);
        $d->post('finance_workflow', $flow);
        $trade = app(FinanceTradeControls::class);
        $trade->saveTerms($b, ['customer_id' => $d->get('customer')->id, 'currency' => 'USD', 'payment_days' => 14, 'credit_limit' => '1000', 'effective_on' => $d->date(), 'agreement' => $d->note('Example agreed payment and credit terms.')], $owner);
        $trade->creditException($b, $d->get('shipment'), ['currency' => 'USD', 'exposure_limit' => '1500', 'expires_on' => $d->date(-7), 'reason' => $d->note('Example dated owner credit exception, no additional dispatch authorised.')], $owner);
        $trade->followup($b, 'customer', $d->get('invoice')->id, ['staff_person_id' => $d->get('staff_sales')->id, 'status' => 'open', 'disputed_amount' => '0', 'next_action_on' => $d->date(-3), 'note' => $d->note('Check buyer payment promise and remaining debt.')], $a);
        $request = $trade->requestAdvance($b, ['staff_person_id' => $d->get('staff_buyer')->id, 'market_trip_id' => $d->get('market_trip')->id, 'currency' => 'UGX', 'amount' => '50000', 'needed_on' => $d->date(-1), 'accountable_on' => $d->date(-3), 'reason' => $d->note('Next market collection approval.'), 'request_key' => $d->key()], $d->id('buyer'));
        $trade->reviewAdvance($b, $request, true, $d->note('Approved example authority; not yet disbursed.'), $owner);
        $rate = app(FinanceRates::class)->propose($b, ['currency' => 'USD', 'rate_to_base' => '3750', 'effective_on' => $d->date(), 'source_note' => $d->note('Illustrative closing rate; not a market quote.')], $a);
        app(FinanceRates::class)->approve($rate, $owner);
        $d->put('closing_rate', $rate);
        $d->stage = 'finance revaluation';
        $revalue = app(FinanceMonetaryRevaluations::class)->prepare($b, ['source_type' => 'commercial_invoice', 'source_id' => $d->get('invoice')->id, 'valued_on' => $d->date(), 'exchange_rate_id' => $rate->id, 'valuation_evidence' => $d->note('Revalue outstanding customer USD debt.')], $a);
        $d->post('monetary_revaluation', $revalue);
        $d->put('document_revaluation',$revalue);
        $d->stage = 'finance revaluation';
        $revalue = app(FinanceFxRevaluations::class)->prepare($b,['bank_profile_id' => $d->get('usd_bank')->id, 'valued_on' => $d->date(), 'exchange_rate_id' => $rate->id, 'valuation_evidence' => $d->note('Revalue named USD bank balance using approved manual rate.')],$a);
        $d->post('fx_revaluation',$revalue);
        $d->put('bank_revaluation',$revalue);
        $d->stage = 'finance money correction';
        $correction = app(FinanceMoneyCorrections::class)->prepare($b,$d->get('staff_claim'),$d->input(['amount' => '5000']),$a);
        $d->post('finance_workflow',$correction);
    }
}
