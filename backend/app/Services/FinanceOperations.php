<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\MarketTrip;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Support\BusinessCurrencies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceOperations
{
    public const KINDS = [
        'customer_deposit' => 'Receive a customer deposit',
        'customer_apply' => 'Use a deposit against an invoice',
        'customer_refund' => 'Refund an unused customer deposit',
        'supplier_advance' => 'Pay a supplier advance',
        'supplier_apply' => 'Use a supplier advance against a bill',
        'supplier_return' => 'Receive an unused supplier advance',
        'staff_advance' => 'Issue a staff / market advance',
        'staff_apply' => 'Account for a market purchase using an advance',
        'staff_expense' => 'Account for an expense using an advance',
        'staff_return' => 'Receive unused staff cash',
        'expense' => 'Record an expense payable / staff claim',
        'paid_expense' => 'Record an expense already paid',
        'expense_payment' => 'Pay an expense / staff claim',
        'transfer' => 'Transfer between accounts in one currency',
    ];

    public const CASH = ['paid_expense', 'customer_deposit', 'customer_refund', 'supplier_advance', 'supplier_return', 'staff_advance', 'staff_return', 'expense_payment', 'transfer'];

    public const CHILDREN = ['customer_apply', 'customer_refund', 'supplier_apply', 'supplier_return', 'staff_apply', 'staff_expense', 'staff_return', 'expense_payment'];

    public function remaining(FinanceOperation $parent, bool $pending = true, ?int $except = null): BigDecimal
    {
        $balance = BigDecimal::of((string) $parent->amount);
        foreach (FinanceOperation::query()->where('business_id', $parent->business_id)->where('parent_id', $parent->id)
            ->whereIn('posting_status', $pending ? ['not_posted', 'posted'] : ['posted'])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->pluck('amount') as $amount) {
            $balance = $balance->minus((string) $amount);
        }

        return $balance->toScale(2);
    }

    public function prepare(Business $business, array $data, int $actor): FinanceOperation
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            $business = app(OrderOperations::class)->lockForWork($business->id, 'finance');
            ksort($data);
            $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = FinanceOperation::query()->where('business_id', $business->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 422, 'This submission reference was already used with different details.');

                return $existing;
            }
            $kind = $data['kind'];
            abort_unless(isset(self::KINDS[$kind]), 422);
            $math = app(FinanceMath::class);
            $amount = $math->money($data['amount']);
            abort_unless($amount->isGreaterThan(0), 422, 'Enter a positive amount.');
            $date = $data['event_on'];
            abort_unless($date <= now($business->timezone)->toDateString(), 422, 'Actual money events cannot be future dated.');
            app(FinancePeriodControls::class)->assertWritable($business, $date);
            $currency = strtoupper($data['currency']);
            abort_unless(in_array($currency, app(BusinessCurrencies::class)->enabled($business), true), 422, 'Enable this business currency first.');
            $parent = null;
            if (in_array($kind, self::CHILDREN, true)) {
                $parent = FinanceOperation::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['parent_id'] ?? 0);
                $expected = match ($kind) {
                    'customer_apply','customer_refund' => 'customer_deposit',
                    'supplier_apply','supplier_return' => 'supplier_advance',
                    'staff_apply','staff_expense','staff_return' => 'staff_advance',
                    'expense_payment' => 'expense',
                };
                abort_unless($parent->posting_status === 'posted' && $parent->kind === $expected && $parent->currency === $currency, 422, 'Choose a posted source of the correct kind and currency.');
                abort_unless($date >= $parent->event_on->toDateString(), 422, 'The allocation or return cannot precede its source.');
                abort_unless(! $amount->isGreaterThan($this->remaining($parent)), 422, 'This exceeds the unused source balance, including pending entries.');
            }
            $entry = [
                'business_id' => $business->id, 'reference' => 'MNY-'.Str::upper((string) Str::ulid()),
                'kind' => $kind, 'currency' => $currency, 'amount' => (string) $amount, 'event_on' => $date,
                'due_on' => $data['due_on'] ?? null, 'description' => $data['description'],
                'external_reference' => $data['external_reference'], 'category' => $data['category'] ?? null,
                'parent_id' => $parent?->id, 'created_by' => $actor, 'posting_status' => 'not_posted',
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $payloadHash,
            ];
            foreach (['customer_id' => Customer::class, 'supplier_id' => Supplier::class, 'staff_person_id' => StaffPerson::class,
                'sales_order_id' => SalesOrder::class, 'market_trip_id' => MarketTrip::class, 'shipment_id' => ExportShipment::class] as $field => $model) {
                $id = $parent?->{$field} ?? ($data[$field] ?? null);
                if ($id) {
                    $related = $model::query()->where('business_id', $business->id)->findOrFail($id);
                    if (in_array($field, ['customer_id', 'supplier_id', 'staff_person_id'], true) && ! $parent) {
                        abort_unless($related->active, 422, 'The selected person or business is inactive.');
                    }
                }
                $entry[$field] = $id ? (int) $id : null;
            }
            $party = match ($kind) {
                'customer_deposit' => 'customer_id','supplier_advance' => 'supplier_id','staff_advance' => 'staff_person_id',default => null
            };
            abort_unless(! $party || $entry[$party], 422, 'Select the recipient of this advance or deposit.');
            if ($entry['shipment_id']) {
                $shipment = ExportShipment::query()->where('business_id', $business->id)->findOrFail($entry['shipment_id']);
                abort_unless(! $entry['sales_order_id'] || $entry['sales_order_id'] === $shipment->sales_order_id, 422, 'Shipment and order do not match.');
                $entry['sales_order_id'] = $entry['sales_order_id'] ?? $shipment->sales_order_id;
            }
            if (! $parent && in_array($kind, ['customer_deposit', 'supplier_advance', 'staff_advance', 'expense', 'paid_expense'], true)) {
                $partyFields = array_filter([$entry['customer_id'], $entry['supplier_id'], $entry['staff_person_id']]);
                abort_unless(count($partyFields) <= 1, 422, 'Choose one customer, supplier or staff recipient.');
            }
            if ($entry['sales_order_id'] && $entry['customer_id']) {
                abort_unless(SalesOrder::query()->where('business_id', $business->id)->findOrFail($entry['sales_order_id'])->customer_id === $entry['customer_id'], 422, 'The order belongs to a different customer.');
            }
            if ($entry['market_trip_id'] && $entry['staff_person_id']) {
                abort_unless(MarketTrip::query()->where('business_id', $business->id)->findOrFail($entry['market_trip_id'])->staff_person_id === $entry['staff_person_id'], 422, 'The market trip is assigned to another person.');
            }
            if (in_array($kind, ['expense', 'staff_expense', 'paid_expense'], true)) {
                abort_unless(! empty($entry['category']), 422, 'Choose an expense category.');
            }
            if (in_array($kind, ['customer_apply', 'supplier_apply', 'staff_apply'], true)) {
                $customer = $kind === 'customer_apply';
                $column = $customer ? 'commercial_invoice_id' : 'supplier_bill_id';
                $document = ($customer ? CommercialInvoice::class : FinanceSupplierBill::class)::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data[$column] ?? 0);
                $entry[$column] = $document->id;
                $this->assertDocument($business, $document, $kind, $entry, $amount, true);
            }
            $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
            if (in_array($kind, ['customer_apply', 'supplier_apply', 'staff_apply'], true)) {
                $rateId = $parent->exchange_rate_id;
            }
            $entry['exchange_rate_id'] = $rateId;
            $entry['rate_to_base'] = app(FinanceRates::class)->rate($business, $currency, $rateId, $date);
            if (in_array($kind, self::CASH, true)) {
                abort_unless($currency === $business->base_currency || ! empty($data['bank_profile_id']), 422, 'Foreign-currency money needs a named account in that currency.');
                if ($kind === 'transfer') {
                    abort_unless($currency === $business->base_currency || ! empty($data['destination_bank_profile_id']), 422, 'The receiving account must also have this currency.');
                }
                $account = app(FinanceBankProfiles::class)->account($business, isset($data['bank_profile_id']) ? (int) $data['bank_profile_id'] : null, $data['cash_account_key'] ?? 'cash', $currency);
                $entry['cash_account_id'] = $account->id;
                if ($kind === 'transfer') {
                    $dest = app(FinanceBankProfiles::class)->account($business, isset($data['destination_bank_profile_id']) ? (int) $data['destination_bank_profile_id'] : null, $data['destination_account_key'] ?? 'bank', $currency);
                    abort_unless($dest->id !== $account->id, 422, 'Choose two different money accounts.');
                    $entry['destination_account_id'] = $dest->id;
                }
            }
            $allocations = [];
            foreach ($data['allocations'] ?? [] as $allocation) {
                $order = SalesOrder::query()->where('business_id', $business->id)->findOrFail($allocation['sales_order_id']);
                abort_unless(! isset($allocations[$order->id]), 422, 'An order may appear only once in the cost split.');
                $allocated = $math->money($allocation['amount']);
                abort_unless($allocated->isGreaterThan(0), 422, 'Cost splits must be positive.');
                $allocations[$order->id] = ['sales_order_id' => $order->id, 'amount' => (string) $allocated, 'basis' => $allocation['basis']];
            }
            if ($allocations) {
                abort_unless(in_array($kind, ['expense', 'staff_expense', 'paid_expense'], true) && $math->sum(array_column($allocations, 'amount'))->isEqualTo($amount), 422, 'Expense splits must account for the whole expense once.');
            } elseif ($entry['sales_order_id'] && in_array($kind, ['expense', 'staff_expense', 'paid_expense'], true)) {
                $allocations[$entry['sales_order_id']] = ['sales_order_id' => $entry['sales_order_id'], 'amount' => (string) $amount, 'basis' => 'Directly identified with this order'];
            }
            $frozen = $entry;
            unset($frozen['posting_status']);
            $snapshot = ['entry' => $frozen, 'allocations' => array_values($allocations), 'classification' => in_array($kind, ['expense', 'paid_expense', 'staff_expense'], true) ? 'period_expense' : ($kind === 'transfer' ? 'cash_transfer' : 'control_balance')];

            return FinanceOperation::create([...$entry, 'data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))]);
        }, 3);
    }

    public function attachEvidence(Business $business, FinanceOperation $operation, UploadedFile $file, int $actor): void
    {
        $path = $file->store('finance-evidence/'.$business->id, 'local');
        try {
            DB::transaction(function () use ($business, $operation, $file, $actor, $path) {
                app(OrderOperations::class)->lockForWork($business->id, 'finance');
                $operation = FinanceOperation::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($operation->id);
                abort_unless($operation->posting_status === 'not_posted' && ! FinancePostingRequest::query()->where('business_id', $business->id)->where('source_type', 'money_operation')->where('source_id', $operation->id)->exists(), 422, 'Supporting evidence is frozen when finance review starts.');
                $snapshot = $operation->data_snapshot;
                $evidence = $snapshot['evidence'] ?? [];
                abort_unless(count($evidence) < 5, 422, 'At most five supporting files can be attached to one money record.');
                $evidence[] = ['path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath()), 'name' => mb_substr($file->getClientOriginalName(), 0, 150), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'uploaded_by' => $actor, 'uploaded_at' => now()->toIso8601String()];
                $snapshot['evidence'] = $evidence;
                $operation->update(['data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))]);
            }, 3);
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function assertDocument(Business $business, CommercialInvoice|FinanceSupplierBill $document, string $kind, array $entry, BigDecimal $amount, bool $pending): void
    {
        abort_unless($document->posting_status === 'posted' && $document->currency === $entry['currency'], 422, 'Choose a posted document in the source currency.');
        if ($document instanceof CommercialInvoice) {
            abort_unless($document->customer_id === $entry['customer_id'], 422, 'The deposit belongs to a different customer.');
            abort_if(FinanceCreditNote::query()->where('business_id', $business->id)->where('commercial_invoice_id', $document->id)->exists(), 422, 'Complete the invoice credit review first.');
        } elseif ($kind === 'supplier_apply') {
            abort_unless($document->purchase->supplier_id === $entry['supplier_id'], 422, 'The advance belongs to a different supplier.');
        } else {
            abort_unless($entry['market_trip_id'] && $document->purchase->market_trip_id === $entry['market_trip_id'], 422, 'The market bill must belong to the advance market trip.');
        }
        $column = $document instanceof CommercialInvoice ? 'commercial_invoice_id' : 'supplier_bill_id';
        abort_if(FinanceBalanceAdjustment::query()->where('business_id', $business->id)->where($column, $document->id)->where('posting_status', 'not_posted')->exists(), 422, 'Complete the pending document adjustment first.');
        abort_unless(! $amount->isGreaterThan(app(FinanceDocuments::class)->outstanding($document, $pending)), 422, 'This exceeds the available document balance.');
    }

    public function lines(FinanceOperation $operation): array
    {
        $business = app(OrderOperations::class)->lockForWork($operation->business_id, 'finance');
        $data = $operation->data_snapshot;
        abort_unless(hash_equals($operation->snapshot_hash, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))), 422, 'The money record snapshot has changed.');
        foreach ($data['evidence'] ?? [] as $file) {
            $disk = Storage::disk('local');
            abort_unless(str_starts_with($file['path'], 'finance-evidence/'.$business->id.'/') && $disk->exists($file['path']) && hash_equals($file['sha256'], hash_file('sha256', $disk->path($file['path']))), 422, 'Supporting evidence is missing or has changed.');
        }
        foreach ($data['entry'] as $key => $value) {
            if (in_array($key, ['amount', 'rate_to_base'], true)) {
                abort_unless(BigDecimal::of((string) $operation->{$key})->isEqualTo((string) $value), 422, 'A frozen financial amount changed.');
            } elseif (in_array($key, ['event_on', 'due_on'], true)) {
                abort_unless($operation->{$key}?->toDateString() === $value, 422, 'A frozen event date changed.');
            } else {
                abort_unless((string) $operation->{$key} === (string) $value, 422, 'A frozen money record changed.');
            }
        }
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $amount = $math->money((string) $operation->amount);
        $base = $math->base($amount, (string) $operation->rate_to_base);
        $kind = $operation->kind;
        $cash = null;
        if (in_array($kind, self::CASH, true)) {
            $cash = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($operation->cash_account_id);
            app(FinanceBankProfiles::class)->assertPaymentAccount($cash, $operation->currency);
        }
        if (in_array($kind, self::CHILDREN, true)) {
            $parent = FinanceOperation::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($operation->parent_id);
            abort_unless($parent->posting_status === 'posted' && ! $amount->isGreaterThan($this->remaining($parent, false, $operation->id)), 422, 'The source balance is no longer available.');
            $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'money_operation')->where('source_id', $parent->id)->firstOrFail();
            abort_unless($operation->event_on->toDateString() >= $journal->posted_on->toDateString(), 422, 'The settlement cannot precede its source journal.');
            $latest = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'money_operation')->whereIn('source_id', FinanceOperation::query()->where('business_id', $business->id)->where('parent_id', $parent->id)->where('posting_status', 'posted')->select('id'))->max('posted_on');
            abort_unless(! $latest || $operation->event_on->toDateString() >= substr((string) $latest, 0, 10), 422, 'The new settlement cannot precede a previously posted allocation or return.');
            $parentBase = $math->base((string) $parent->amount, (string) $journal->rate_to_base);
            foreach (FinanceOperation::query()->where('business_id', $business->id)->where('parent_id', $parent->id)->where('posting_status', 'posted')->whereKeyNot($operation->id)->pluck('consumed_base') as $used) {
                $parentBase = $parentBase->minus((string) $used);
            }
            $remaining = $this->remaining($parent, false, $operation->id);
            $carrying = $amount->isEqualTo($remaining) ? $parentBase : $math->money($parentBase->multipliedBy($amount)->dividedBy($remaining, 14, RoundingMode::HalfUp));
            $operation->update(['consumed_base' => (string) $carrying]);
        }
        $debit = $credit = null;
        $debitBase = $creditBase = $base;
        $extra = [];
        if (in_array($kind, ['customer_deposit', 'supplier_advance', 'staff_advance', 'expense', 'paid_expense', 'transfer'], true)) {
            [$debit,$credit] = match ($kind) {
                'customer_deposit' => [$cash->system_key, 'customer_deposits'],
                'supplier_advance' => ['supplier_advances', $cash->system_key],
                'staff_advance' => ['staff_advances', $cash->system_key],
                'expense' => ['general_expense', 'expense_payable'],
                'paid_expense' => ['general_expense', $cash->system_key],
                'transfer' => [$this->destination($business, $operation)->system_key, $cash->system_key],
            };
        } elseif (in_array($kind, ['customer_apply', 'supplier_apply', 'staff_apply'], true)) {
            $customer = $kind === 'customer_apply';
            $document = ($customer ? CommercialInvoice::class : FinanceSupplierBill::class)::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($customer ? $operation->commercial_invoice_id : $operation->supplier_bill_id);
            $this->assertDocument($business, $document, $kind, $data['entry'], $amount, false);
            $settlement = app(FinanceSettledCarrying::class)->portion($business, $document, $amount, $operation->event_on->toDateString());
            if ($customer) {
                [$debit,$credit] = ['customer_deposits', 'receivable'];
                $debitBase = $carrying;
                $creditBase = $settlement['base'];
            } else {
                [$debit,$credit] = ['payable', $kind === 'supplier_apply' ? 'supplier_advances' : 'staff_advances'];
                $debitBase = $settlement['base'];
                $creditBase = $carrying;
            }
            $extra = $settlement['reclass'];
        } else {
            [$debit,$credit] = match ($kind) {
                'customer_refund' => ['customer_deposits', $cash->system_key],
                'supplier_return' => [$cash->system_key, 'supplier_advances'],
                'staff_return' => [$cash->system_key, 'staff_advances'],
                'staff_expense' => ['general_expense', 'staff_advances'],
                'expense_payment' => ['expense_payable', $cash->system_key],
            };
            if (in_array($kind, ['customer_refund', 'expense_payment'], true)) {
                $debitBase = $carrying;
            } else {
                $creditBase = $carrying;
            }
        }
        $lines = [$ledger->line($debit, (string) $amount, '0', (string) $debitBase, '0', $operation->description), $ledger->line($credit, '0', (string) $amount, '0', (string) $creditBase, $operation->external_reference)];
        $difference = $debitBase->minus($creditBase);
        if ($difference->isGreaterThan(0)) {
            $lines[] = $ledger->line('fx_gain', '0', '0', '0', (string) $difference, 'Realised historical FX difference');
        } elseif ($difference->isLessThan(0)) {
            $lines[] = $ledger->line('fx_loss', '0', '0', (string) $difference->negated(), '0', 'Realised historical FX difference');
        }

        return [...$lines, ...$extra];
    }

    private function destination(Business $business, FinanceOperation $operation): FinanceAccount
    {
        $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($operation->destination_account_id);
        app(FinanceBankProfiles::class)->assertPaymentAccount($account, $operation->currency);
        abort_unless($account->id !== $operation->cash_account_id, 422);

        return $account;
    }
}
