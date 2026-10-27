<?php

namespace Database\Seeders;

use App\Services\FinanceBankAuthenticity;
use App\Services\FinanceBankLedgerProofs;
use App\Services\FinanceBankReconciliation;
use App\Services\FinanceDocuments;
use App\Services\FinancePartialBankReturns;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ExporterDemoBankSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $a = $d->id('accountant');
        $owner = $d->id('owner');
        $bank = $d->get('ugx_bank');
        $today = $d->date();
        $payment = app(FinanceDocuments::class)->payment($b, ['direction' => 'out', 'supplier_bill_id' => $d->get('bill_supplier')->id, 'amount' => '10000', 'payment_on' => $today, 'cash_account_key' => 'bank', 'bank_profile_id' => $bank->id, 'external_reference' => 'DEMO-BANK-SUPPLIER-PAYMENT', 'notes' => $d->note('Synthetic bank payment for reconciliation training.'), 'idempotency_key' => $d->key()], $a);
        $d->post('payment', $payment);
        $service = app(FinanceBankReconciliation::class);
        $csv = "date,direction,amount,reference,description\n$today,debit,10000,DEMO-BANK-SUPPLIER-PAYMENT,DEMO ONLY synthetic supplier payment\n";
        $path = $d->file('demo-bank-evidence', 'original.csv', $csv);
        $statement = $service->import($b, ['bank_profile_id' => $bank->id, 'currency' => 'UGX', 'period_from' => $today, 'period_to' => $today, 'opening_balance' => '5000000', 'closing_balance' => '4990000'], new UploadedFile(Storage::disk('local')->path($path), 'DEMO-bank-original.csv', 'text/csv', null, true), $a);
        $line = $statement->lines()->firstOrFail();
        $match = $service->propose($line, 'payment', $payment->id, $d->note('Match synthetic bank debit to its posted supplier payment.'), $a);
        $service->review($match, true, $d->note('Independent synthetic bank line check.'), $owner);
        $service->complete($statement, $owner);
        $proof = app(FinanceBankLedgerProofs::class)->submit($statement->fresh(), $a, $d->note('Ledger totals match this simulated CSV. Awaiting independent review and a real bank issuer.'));
        $d->put('bank_proof', $proof);
        $auth = app(FinanceBankAuthenticity::class);
        $auth->setAccountIdentifier($b, $bank, 'DEMOUGX000001', $owner);
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = openssl_pkey_get_details($private)['key'];
        $issuers = config('finance_banks.trusted_issuers');
        try {
            config(['finance_banks.trusted_issuers' => [...($issuers ?? []), 'DEMO-UNTRUSTED-TRAINING' => ['active' => true, 'verified_out_of_band' => true, 'institution' => $bank->institution, 'key_id' => 'DEMO-EPHEMERAL', 'public_key_pem' => $public]]]);
            $payload = json_encode(['version' => 1, 'issuer_id' => 'DEMO-UNTRUSTED-TRAINING', 'key_id' => 'DEMO-EPHEMERAL', 'account_identifier_sha256' => hash('sha256', 'DEMOUGX000001'), 'file_sha256' => $statement->file_sha256, 'currency' => 'UGX', 'period_from' => $today, 'period_to' => $today, 'opening_balance' => '5000000', 'closing_balance' => '4990000', 'credits' => '0', 'debits' => '10000', 'statement_reference' => 'DEMO ONLY — ephemeral locally signed fixture; not a real bank attestation', 'signed_at' => now()->subSecond()->toIso8601String()], JSON_THROW_ON_ERROR);
            openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
            $auth->verifyAndRecord($statement, $payload, base64_encode($signature), $owner);
        } finally {
            config(['finance_banks.trusted_issuers' => $issuers]);
        }
        $csv = "date,direction,amount,reference,description\n$today,credit,2000,DEMO-PART-RETURN,DEMO ONLY synthetic partial supplier payment return\n";
        $path = $d->file('demo-bank-evidence', 'return.csv', $csv);
        $returned = $service->import($b, ['bank_profile_id' => $bank->id, 'currency' => 'UGX', 'period_from' => $today, 'period_to' => $today, 'opening_balance' => '4990000', 'closing_balance' => '4992000'], new UploadedFile(Storage::disk('local')->path($path), 'DEMO-bank-return.csv', 'text/csv', null, true), $a);
        app(FinancePartialBankReturns::class)->prepare($b, 'payment', $payment->id, $returned->lines()->firstOrFail()->id, $d->note('Partial payment return awaiting review; original bank debit remains reconciled.'), $d->note('Synthetic CSV is illustrative, not an authenticated bank source.'), $a);
        $d->put('bank_statement',$statement);
    }
}
