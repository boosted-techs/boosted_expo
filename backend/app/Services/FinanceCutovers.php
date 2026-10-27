<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceCutover;
use App\Models\FinanceJournal;
use App\Models\FinanceOpeningItem;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceWorkflow;
use App\Models\LoanFacility;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesRequest;
use App\Models\SalesRequestItem;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockBatchOrigin;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\BusinessCurrencies;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceCutovers
{
    public const COLUMNS = ['type', 'reference', 'party_code', 'item_code', 'currency', 'amount', 'quantity', 'rate_id', 'due_on', 'location', 'order_reference', 'notes'];

    public const CONTROLS = ['customer_debt' => ['receivable', 'asset'], 'supplier_debt' => ['payable', 'liability'], 'supplier_advance' => ['supplier_advances', 'asset'], 'staff_advance' => ['staff_advances', 'asset'], 'customer_deposit' => ['customer_deposits', 'liability']];

    public function readCsv(string $path): array
    {
        $file = fopen($path, 'r');
        try {
            $header = fgetcsv($file, 0, ',', '"', '');
            abort_unless(is_array($header), 422, 'Upload the completed opening-data CSV.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            abort_unless($header === self::COLUMNS, 422, 'Keep the downloaded template column names and order.');
            $rows = [];
            while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                abort_unless(count($values) === count($header) && count($rows) < 1000, 422, 'Use the template columns and at most 1,000 rows per cutover.');
                $row = array_combine($header, array_map(fn ($v) => trim((string) $v), $values));
                foreach ($row as $value) {
                    abort_unless(mb_strlen($value) <= 3000, 422, 'An opening-data cell is too long.');
                }
                $rows[] = $row;
            }
            abort_unless($rows !== [], 422, 'The opening file contains no records.');

            return $rows;
        } finally {
            fclose($file);
        }
    }

    private function accountBalances(Business $b, string $date): array
    {
        $result = [];
        foreach (DB::table('finance_journal_lines as l')->join('finance_journals as j', 'j.id', '=', 'l.journal_id')->join('finance_accounts as a', 'a.id', '=', 'l.account_id')->where('l.business_id', $b->id)->where('j.business_id', $b->id)->where('a.business_id', $b->id)->whereDate('j.posted_on', '<=', $date)->get(['a.system_key', 'l.base_debit', 'l.base_credit']) as $row) {
            $result[$row->system_key] = ($result[$row->system_key] ?? BigDecimal::zero())->plus((string) $row->base_debit)->minus((string) $row->base_credit);
        }

        return $result;
    }

    private function controls(array $expected, array $actual): array
    {
        $rows = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            $declared = BigDecimal::of((string) ($expected[$key] ?? '0'));
            $book = BigDecimal::of((string) ($actual[$key] ?? '0'));
            if ($declared->isZero() && $book->isZero()) {
                continue;
            }
            $rows[] = ['account' => $key, 'declared' => (string) $declared->toScale(2), 'book' => (string) $book->toScale(2), 'difference' => (string) $book->minus($declared)->toScale(2)];
        }

        return $rows;
    }

    private function assertBeforeActivity(Business $b, string $date): void
    {
        foreach (FinanceJournal::query()->where('business_id', $b->id)->whereDate('posted_on', '<=', $date)->cursor() as $journal) {
            $opening = in_array($journal->source_type, ['opening_balance', 'foreign_bank_opening'], true);
            if ($journal->source_type === 'register_entry') {
                $opening = FinanceRegisterEntry::query()->where('business_id', $b->id)->whereKey($journal->source_id)->whereIn('kind', ['asset_opening', 'loan_opening'])->exists();
            }
            abort_unless($opening, 422, 'Choose an opening date before the first actual financial activity. Earlier verified asset/loan/bank openings can be reconciled in this cutover.');
        }
    }

    public function prepare(Business $b, array $data, array $rows, int $actor): FinanceCutover
    {
        return DB::transaction(function () use ($b, $data, $rows, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $hash = app(FinanceWorkflows::class)->hash([$data, $rows]);
            if ($old = FinanceCutover::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first()) {
                abort_unless($old->payload_hash === $hash, 409, 'This import key already has a different file or opening date.');

                return $old;
            }
            abort_if(FinanceCutover::query()->where('business_id', $b->id)->whereNotIn('status', ['withdrawn', 'rejected'])->exists(), 422, 'Finish the existing opening cutover. An opening file cannot be imported twice.');
            app(FinancePeriodControls::class)->assertWritable($b, $data['effective_on']);
            abort_unless($data['effective_on'] <= now($b->timezone)->toDateString(), 422, 'Use an actual signed opening date.');
            $this->assertBeforeActivity($b, $data['effective_on']);
            $math = app(FinanceMath::class);
            $ledger = app(FinanceLedger::class);
            $legs = [];
            $normalized = [];
            $expected = [];
            $keys = [];
            $projected = $this->accountBalances($b, $data['effective_on']);
            foreach ($rows as $index => $row) {
                $rules = ['type' => ['required', 'in:control,stock,cash,customer_debt,supplier_debt,supplier_advance,staff_advance,customer_deposit,asset,loan,order,purchase_supplier,purchase_market'], 'reference' => ['required', 'string', 'max:150'], 'due_on' => ['nullable', 'date_format:Y-m-d'], 'rate_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['nullable', 'numeric', 'decimal:0,'.(str_starts_with($row['type'], 'purchase_') ? '4' : '2'), 'between:-999999999999,999999999999'], 'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'between:0,999999999']];
                $validated = array_map(fn ($v) => $v === '' ? null : $v, $row);
                $validation = Validator::make($validated, $rules);
                if ($validation->fails()) {
                    throw ValidationException::withMessages(['file' => 'Opening CSV row '.($index + 2).': '.$validation->errors()->first()]);
                }

                $kind = $row['type'];
                $key = $kind.':'.$row['reference'];
                abort_unless($row['reference'] !== '' && ! isset($keys[$key]), 422, 'Each opening row needs a unique type/reference, including each order line.');
                $keys[$key] = true;
                if ($kind === 'control') {
                    $account = FinanceAccount::query()->where('business_id', $b->id)->where('active', true)->where('system_key', $row['item_code'])->firstOrFail();
                    abort_unless(in_array($account->type, ['asset', 'liability', 'equity'], true) && ! isset($expected[$account->system_key]), 422, 'List each opening control account once.');
                    $expected[$account->system_key] = (string) $math->money($row['amount']);

                    continue;
                }
                abort_unless(mb_strlen($row['notes']) >= 20, 422, 'Each opening item needs its signed source/count/agreement evidence in Notes.');
                $n = $row;
                abort_unless(mb_strlen($row['reference']) <= 150 && mb_strlen($row['item_code']) <= 150 && mb_strlen($row['party_code']) <= 160 && mb_strlen($row['location']) <= 150 && mb_strlen($row['order_reference']) <= 150, 422, 'Opening references/codes exceed the template field sizes.');
                foreach (['due_on'] as $field) {
                    if ($row[$field] !== '') {
                        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$field]) && Carbon::parse($row[$field])->toDateString() === $row[$field], 422, 'Use real YYYY-MM-DD opening dates.');
                    }
                }
                if (in_array($kind, ['asset', 'loan'], true)) {
                    $record = ($kind === 'asset' ? BusinessAsset::query()->where('asset_code', $row['item_code']) : LoanFacility::query()->where('reference', $row['item_code']))->where('business_id', $b->id)->firstOrFail();
                    abort_unless($row['currency'] === ($kind === 'asset' ? $b->base_currency : $record->currency), 422, 'An asset/loan opening row must use its original register currency.');
                    $position = app(FinanceRegisterPostings::class)->position($record, $data['effective_on']);
                    $value = $kind === 'asset' ? app(AssetRegister::class)->state($record, $position)['book'] : app(LoanRegister::class)->state($record, $position)['principal'];
                    abort_unless($value->isEqualTo($row['amount']) && $value->isGreaterThan(0), 422, 'The asset/loan row must agree with its independently posted register opening by the cutover date.');
                    $n['record_id'] = $record->id;
                    $n['source_fingerprint'] = $position['fingerprint'];
                    $normalized[] = $n;

                    continue;
                }
                if (in_array($kind, ['order', 'purchase_supplier', 'purchase_market'], true)) {
                    app(PlatformTenancy::class)->assertModuleWrite($b, $kind === 'order' ? 'sales' : 'procurement');
                    $product = Product::query()->where('business_id', $b->id)->where('active', true)->where('sku', $row['item_code'])->firstOrFail();
                    $n['product_id'] = $product->id;
                    abort_unless(BigDecimal::of($row['quantity'])->isGreaterThan(0) && $row['due_on'] !== '', 422, 'A pending order/purchase needs its agreed quantity and due date.');
                    $n['quantity'] = (string) BigDecimal::of($row['quantity'])->toScale(3);
                    if ($kind === 'order') {
                        $party = Customer::query()->where('business_id', $b->id)->where('active', true)->where('customer_code', $row['party_code'])->firstOrFail();
                        $n['customer_id'] = $party->id;
                        abort_unless($row['amount'] === '' || BigDecimal::of($row['amount'])->isZero(), 422, 'Import quantity-only pending demand. Agree prices through the quotation workflow.');
                    } elseif ($kind === 'purchase_supplier') {
                        $n['supplier_id'] = Supplier::query()->where('business_id', $b->id)->where('active', true)->where('supplier_code', $row['party_code'])->firstOrFail()->id;
                    } else {
                        abort_unless($row['party_code'] !== '', 422, 'Identify the actual market vendor.');
                    }
                    if ($kind !== 'order') {
                        abort_unless(BigDecimal::of($row['amount'])->isGreaterThan(0) && in_array($row['currency'], app(BusinessCurrencies::class)->enabled($b), true), 422, 'A pending purchase needs its agreed unit price and enabled currency.');
                        $n['amount'] = (string) BigDecimal::of($row['amount'])->toScale(4);
                    }
                    $normalized[] = $n;

                    continue;
                }
                abort_unless(in_array($kind, ['stock', 'cash', ...array_keys(self::CONTROLS)], true), 422, 'Choose a supported opening row type.');
                $amount = $math->money($row['amount']);
                abort_unless($amount->isGreaterThan(0), 422, 'Each financial opening item needs a positive amount.');
                $currency = $kind === 'stock' ? $b->base_currency : strtoupper($row['currency']);
                $rateId = $row['rate_id'] !== '' ? (int) $row['rate_id'] : null;
                $rate = app(FinanceRates::class)->rate($b, $currency, $rateId, $data['effective_on']);
                $base = $math->base($amount, $rate);
                $account = null;
                $debit = true;
                if ($kind === 'stock') {
                    app(PlatformTenancy::class)->assertModuleWrite($b, 'procurement');
                    $n['product_id'] = Product::query()->where('business_id', $b->id)->where('active', true)->where('sku', $row['item_code'])->firstOrFail()->id;
                    $n['location_id'] = StockLocation::query()->where('business_id', $b->id)->where('active', true)->where('name', $row['location'])->firstOrFail()->id;
                    abort_unless(BigDecimal::of($row['quantity'])->isGreaterThan(0) && ! StockBatch::query()->where('business_id', $b->id)->where('batch_code', $row['reference'])->exists(), 422, 'Use a positive observed quantity and a new batch reference.');
                    $n['quantity'] = (string) BigDecimal::of($row['quantity'])->toScale(3);
                    $account = 'inventory';
                } elseif ($kind === 'cash') {
                    $money = FinanceAccount::query()->where('business_id', $b->id)->where('active', true)->where('type', 'asset')->where('system_key', $row['item_code'])->firstOrFail();
                    app(FinanceTreasury::class)->assertAccount($money, $currency);
                    abort_if(DB::table('finance_journal_lines')->where('business_id', $b->id)->where('account_id', $money->id)->exists(), 422, 'This money account already has activity. Reconcile its existing opening rather than importing it again.');
                    $account = $money->system_key;
                    $n['account_id'] = $money->id;
                } else {
                    [$account, $type] = self::CONTROLS[$kind];
                    $debit = $type === 'asset';
                    if (in_array($kind, ['customer_debt', 'customer_deposit'], true)) {
                        $n['customer_id'] = Customer::query()->where('business_id', $b->id)->where('active', true)->where('customer_code', $row['party_code'])->firstOrFail()->id;
                    } elseif (in_array($kind, ['supplier_debt', 'supplier_advance'], true)) {
                        $n['supplier_id'] = Supplier::query()->where('business_id', $b->id)->where('active', true)->where('supplier_code', $row['party_code'])->firstOrFail()->id;
                    } else {
                        $n['staff_person_id'] = StaffPerson::query()->where('business_id', $b->id)->where('active', true)->where('staff_code', $row['party_code'])->firstOrFail()->id;
                    }
                    abort_if(FinanceOpeningItem::query()->where('business_id', $b->id)->where('kind', $kind)->where('reference', $row['reference'])->exists(), 422, 'This opening debt/advance reference already exists.');
                }
                $lines = [$ledger->line($account, $debit ? (string) $amount : '0', $debit ? '0' : (string) $amount, $debit ? (string) $base : '0', $debit ? '0' : (string) $base, 'Verified opening '.$kind.' · '.$row['reference']), $ledger->line('opening_equity', $debit ? '0' : (string) $amount, $debit ? (string) $amount : '0', $debit ? '0' : (string) $base, $debit ? (string) $base : '0', 'Historical opening counterpart; no invented sale or payment')];
                foreach ($lines as $line) {
                    $projected[$line['account']] = ($projected[$line['account']] ?? BigDecimal::zero())->plus($line['base_debit'])->minus($line['base_credit']);
                }
                $n = [...$n, 'currency' => $currency, 'amount' => (string) $amount, 'base_amount' => (string) $base, 'account' => $account, 'leg_sequence' => count($legs)];
                $legs[] = ['currency' => $currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $rateId, 'amount' => (string) $amount, 'lines' => $lines];
                $normalized[] = $n;
            }
            abort_unless($expected !== [], 422, 'Include independently prepared control rows in the reporting currency, with debit balances positive and credit balances negative.');
            $controls = $this->controls($expected, $projected);
            abort_unless(collect($controls)->every(fn ($r) => BigDecimal::of($r['difference'])->isZero()), 422, 'The opening file does not reconcile to its declared control totals. Check every stock, trade, cash, asset, loan and historical equity balance.');
            $snapshot = ['file_sha256' => $data['file_sha256'] ?? null, 'effective_on' => $data['effective_on'], 'external_reference' => $data['external_reference'], 'reason' => $data['reason'], 'rows' => $normalized, 'expected' => $expected, 'controls' => $controls];
            $cutover = FinanceCutover::create(['business_id' => $b->id, 'reference' => 'CUT-'.Str::upper((string) Str::ulid()), 'effective_on' => $data['effective_on'], 'status' => $legs ? 'prepared' : 'posted', 'data_snapshot' => $snapshot, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($snapshot), 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
            if ($legs) {
                $flow = app(FinanceWorkflows::class)->create($b, 'cutover_opening', ['event_on' => $data['effective_on'], 'reason' => $data['reason'], 'external_reference' => $data['external_reference'], 'request_key' => $data['request_key']], $actor, $legs, ['cutover_id' => $cutover->id, 'cutover_hash' => $cutover->snapshot_hash]);
                $cutover->update(['workflow_id' => $flow->id]);
            } else {
                $this->createOperationalSources($b, $cutover, $actor);
            }
            app(PlatformAccess::class)->audit($b->id, $actor, 'finance.cutover_prepared', $data['reason'], [], ['cutover_id' => $cutover->id, 'row_count' => count($normalized), 'controls' => $controls]);

            return $cutover;
        }, 3);
    }

    public function validate(FinanceWorkflow $flow): void
    {
        $cutover = FinanceCutover::query()->where('business_id', $flow->business_id)->findOrFail($flow->data_snapshot['details']['cutover_id']);
        abort_unless($cutover->status === 'prepared' && $cutover->workflow_id === $flow->id && $cutover->snapshot_hash === $flow->data_snapshot['details']['cutover_hash'] && hash_equals($cutover->snapshot_hash, app(FinanceWorkflows::class)->hash($cutover->data_snapshot)), 422, 'The opening import evidence changed.');
        $this->assertEvidence($cutover);
        $b = Business::findOrFail($flow->business_id);
        $this->assertBeforeActivity($b, $cutover->effective_on->toDateString());
        foreach ($cutover->data_snapshot['rows'] as $row) {
            if ($row['type'] === 'stock') {
                abort_if(StockBatch::query()->where('business_id', $b->id)->where('batch_code', $row['reference'])->exists(), 422, 'An opening batch reference was already used.');
            }
            if (in_array($row['type'], ['asset', 'loan'], true)) {
                $record = ($row['type'] === 'asset' ? BusinessAsset::query() : LoanFacility::query())->where('business_id', $b->id)->findOrFail($row['record_id']);
                abort_unless(app(FinanceRegisterPostings::class)->position($record, $cutover->effective_on->toDateString())['fingerprint'] === $row['source_fingerprint'], 422, 'A linked register opening changed.');
            }
        }
        $projected = $this->accountBalances($b, $cutover->effective_on->toDateString());
        foreach ($flow->legs as $leg) {
            foreach ($leg->lines as $line) {
                $projected[$line['account']] = ($projected[$line['account']] ?? BigDecimal::zero())->plus($line['base_debit'])->minus($line['base_credit']);
            }
        }
        abort_unless(collect($this->controls($cutover->data_snapshot['expected'], $projected))->every(fn ($r) => BigDecimal::of($r['difference'])->isZero()), 422, 'Existing ledger openings changed after import. Reconcile and prepare the file again.');
    }

    public function finalize(FinanceWorkflow $flow, int $actor): void
    {
        $cutover = FinanceCutover::query()->where('business_id', $flow->business_id)->findOrFail($flow->data_snapshot['details']['cutover_id']);
        $b = Business::findOrFail($flow->business_id);
        foreach ($cutover->data_snapshot['rows'] as $row) {
            if (! isset($row['leg_sequence'])) {
                continue;
            }
            $leg = $flow->legs()->where('sequence', $row['leg_sequence'])->firstOrFail();
            if ($row['type'] === 'stock') {
                $batch = StockBatch::create(['business_id' => $b->id, 'product_id' => $row['product_id'], 'batch_code' => $row['reference'], 'received_on' => $cutover->effective_on->toDateString(), 'stock_location_id' => $row['location_id'], 'source_reference' => $cutover->reference]);
                $proof = ['batch_id' => $batch->id, 'quantity' => $row['quantity'], 'base_cost' => $row['base_amount'], 'kind' => 'cutover', 'source' => ['cutover_id' => $cutover->id, 'cutover_hash' => $cutover->snapshot_hash, 'evidence' => $row['notes']]];
                StockBatchOrigin::create(['business_id' => $b->id, 'batch_id' => $batch->id, 'workflow_id' => $flow->id, 'kind' => 'cutover', 'quantity' => $row['quantity'], 'base_cost' => $row['base_amount'], 'data_snapshot' => $proof, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($proof)]);
                StockMovement::create(['business_id' => $b->id, 'batch_id' => $batch->id, 'occurred_on' => $cutover->effective_on->toDateString(), 'movement_type' => 'cutover_opening', 'quantity' => $row['quantity'], 'unit' => $batch->product->base_unit, 'source_type' => 'finance_cutover', 'source_id' => $cutover->id, 'created_by' => $actor]);
            } elseif (isset(self::CONTROLS[$row['type']])) {
                FinanceOpeningItem::create(['business_id' => $b->id, 'cutover_id' => $cutover->id, 'workflow_id' => $flow->id, 'workflow_leg_id' => $leg->id, 'kind' => $row['type'], 'reference' => $row['reference'], 'currency' => $row['currency'], 'amount' => $row['amount'], 'base_amount' => $row['base_amount'], 'customer_id' => $row['customer_id'] ?? null, 'supplier_id' => $row['supplier_id'] ?? null, 'staff_person_id' => $row['staff_person_id'] ?? null, 'due_on' => $row['due_on'] ?: null, 'data_snapshot' => $row, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($row)]);
            }
        }
        $this->createOperationalSources($b, $cutover, $actor);
        $cutover->update(['status' => 'posted']);
    }

    private function createOperationalSources(Business $b, FinanceCutover $cutover, int $actor): void
    {
        $orders = [];
        $groups = collect($cutover->data_snapshot['rows'])->where('type', 'order')->groupBy(fn ($r) => $r['order_reference'] ?: $r['reference']);
        foreach ($groups as $reference => $lines) {
            $row = $lines->first();
            foreach ($lines as $line) {
                abort_unless($line['customer_id'] === $row['customer_id'] && $line['due_on'] === $row['due_on'], 422, 'Lines grouped in one pending order must share customer and required date.');
            }
            abort_unless($lines->pluck('product_id')->unique()->count() === $lines->count(), 422, 'Combine the same product quantity within each pending order.');
            $request = SalesRequest::create(['business_id' => $b->id, 'reference' => 'CUT-ORDER-'.Str::upper((string) Str::ulid()), 'customer_id' => $row['customer_id'], 'source' => 'cutover', 'buyer_reference' => (string) $reference, 'status' => 'open', 'required_on' => $row['due_on'], 'notes' => $cutover->reference.' · '.$row['notes'], 'created_by' => $actor, 'submission_key' => (string) Str::uuid()]);
            foreach ($lines as $line) {
                SalesRequestItem::create(['business_id' => $b->id, 'sales_request_id' => $request->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity']]);
            }
            $orders[$reference] = app(OrderOperations::class)->approve($b, $request->id, $actor, 'Verified pending demand imported through '.$cutover->reference);
        }
        foreach ($cutover->data_snapshot['rows'] as $row) {
            if (! in_array($row['type'], ['purchase_supplier', 'purchase_market'], true)) {
                continue;
            }
            abort_if(PurchaseOrder::query()->where('business_id', $b->id)->where('reference', $row['reference'])->exists(), 422, 'An imported pending purchase reference already exists.');
            $purchase = PurchaseOrder::create(['business_id' => $b->id, 'reference' => $row['reference'], 'kind' => $row['type'] === 'purchase_supplier' ? 'supplier' : 'market', 'product_id' => $row['product_id'], 'supplier_id' => $row['supplier_id'] ?? null, 'vendor_name' => $row['type'] === 'purchase_market' ? $row['party_code'] : null, 'purchased_on' => $cutover->effective_on->toDateString(), 'expected_on' => $row['due_on'], 'quantity' => $row['quantity'], 'unit_price' => $row['amount'], 'estimated_total' => app(ProcurementMath::class)->amount($row['quantity'], $row['amount']), 'currency' => $row['currency'], 'status' => 'ordered', 'notes' => $cutover->reference.' · '.$row['notes'], 'created_by' => $actor, 'idempotency_key' => (string) Str::uuid()]);
            if ($row['order_reference'] !== '') {
                $order = $orders[$row['order_reference']] ?? null;
                abort_unless($order, 422, 'Link a pending purchase to an order reference present in this file.');
                $line = $order->lines()->where('business_id', $b->id)->where('product_id', $row['product_id'])->firstOrFail();
                app(OrderSourcing::class)->supply($line, ['kind' => 'purchase', 'purchase_order_id' => $purchase->id, 'quantity' => $row['quantity'], 'reason' => $row['notes'], 'request_key' => (string) Str::uuid()], $actor);
            }
        }
    }

    private function assertEvidence(FinanceCutover $cutover): void
    {
        if (! ($cutover->data_snapshot['file_sha256'] ?? null)) {
            return;
        }
        $disk = Storage::disk('local');
        abort_unless($cutover->source_file_path && str_starts_with($cutover->source_file_path, 'cutover-evidence/'.$cutover->business_id.'/') && $disk->exists($cutover->source_file_path) && $cutover->source_file_sha256 === $cutover->data_snapshot['file_sha256'] && hash_equals($cutover->source_file_sha256, hash_file('sha256', $disk->path($cutover->source_file_path))), 422, 'The original signed opening file is missing or changed.');
    }

    public function accept(Business $b, FinanceCutover $cutover, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $cutover, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $cutover = FinanceCutover::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($cutover->id);
            abort_unless($cutover->status === 'posted' && $cutover->created_by !== $actor && hash_equals($cutover->snapshot_hash, app(FinanceWorkflows::class)->hash($cutover->data_snapshot)), 422, 'A separate owner must accept the posted opening sources.');
            $this->assertEvidence($cutover);
            $controls = $this->controls($cutover->data_snapshot['expected'], $this->accountBalances($b, $cutover->effective_on->toDateString()));
            abort_unless(collect($controls)->every(fn ($r) => BigDecimal::of($r['difference'])->isZero()), 422, 'Opening control totals no longer reconcile.');
            $balances = $this->accountBalances($b, $cutover->effective_on->toDateString());
            $register = app(FinanceRegisterReconciliation::class)->report($b, $cutover->effective_on->toDateString(), $balances);
            abort_unless($register['matches'], 422, 'Equipment and borrowing opening balances need their real, independently posted register sources.');
            $inventory = app(FinanceInventoryReconciliation::class)->report($b, $cutover->effective_on->toDateString());
            abort_unless($inventory['matches'], 422, 'Opening stock quantity and value sources need reconciliation before acceptance.');
            foreach (FinanceJournal::query()->where('business_id', $b->id)->whereDate('posted_on', '<=', $cutover->effective_on)->with('lines')->get() as $journal) {
                $evidence = app(FinanceManagementReports::class)->journalEvidence($journal);
                abort_unless($journal->source_snapshot && $evidence == $journal->source_snapshot && hash_equals((string) $journal->source_hash, app(FinanceWorkflows::class)->hash($evidence)), 422, 'Original opening posting evidence is missing or changed.');
            }
            $snapshot = ['inventory' => $inventory, 'controls' => $controls, 'registers' => $register, 'opening_hash' => $cutover->snapshot_hash, 'accepted_by' => $actor, 'note' => $note];
            $cutover->update(['status' => 'accepted', 'accepted_by' => $actor, 'accepted_at' => now(), 'acceptance_note' => $note, 'acceptance_snapshot' => $snapshot, 'acceptance_hash' => app(FinanceWorkflows::class)->hash($snapshot)]);
            app(PlatformAccess::class)->audit($b->id, $actor, 'finance.cutover_accepted', $note, [], ['cutover_id' => $cutover->id, 'acceptance_hash' => $cutover->acceptance_hash]);
        }, 3);
    }
}
