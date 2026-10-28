<?php

namespace App\Services;

use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\FinanceInventoryRelief;
use App\Models\FinancePayment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockBatch;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CatalogueStatements
{
    private function event(int $business, string $table, string $date, string $kind, string $reference, string $description, string $quantity = 'NULL', string $amount = 'NULL', string $currency = 'NULL', string $status = "'recorded'"): Builder
    {
        return DB::table($table)->where($table.'.business_id', $business)->selectRaw("COALESCE($date,$table.created_at) as activity_on, $table.id as record_id, ? as kind, $reference as reference, $description as description, $quantity as quantity, $amount as amount, $currency as currency, $status as status", [$kind]);
    }

    public function activity(Request $request, Customer|Product $record, array $filters): LengthAwarePaginator
    {
        $business = $request->attributes->get('activeBusiness');
        $access = app(BusinessPermissions::class);
        $member = $request->attributes->get('membership');
        $can = fn (string $p) => $access->allows($member, $p);
        $customer = $record instanceof Customer;
        $queries = [];
        if ($can('sales.orders.view')) {
            $q = $this->event($business->id, 'sales_orders', 'COALESCE(sales_orders.confirmed_at,sales_orders.created_at)', 'order', 'sales_orders.reference', "'Customer order'", 'NULL', $can('sales.manage') || $can('finance.view') ? 'sales_orders.grand_total' : 'NULL', 'sales_orders.currency', 'sales_orders.status');
            if ($customer) {
                $q->where('sales_orders.customer_id', $record->id);
            } else {
                $q = $this->event($business->id, 'sales_orders', 'COALESCE(sales_orders.confirmed_at,sales_orders.created_at)', 'order', 'sales_orders.reference', 'customers.name', 'sales_order_lines.quantity', $can('sales.manage') || $can('finance.view') ? 'sales_order_lines.line_total' : 'NULL', 'sales_orders.currency', 'sales_orders.status')
                    ->join('sales_order_lines', fn ($j) => $j->on('sales_order_lines.sales_order_id', '=', 'sales_orders.id')->on('sales_order_lines.business_id', '=', 'sales_orders.business_id'))->join('customers', fn ($j) => $j->on('customers.id', '=', 'sales_orders.customer_id')->on('customers.business_id', '=', 'sales_orders.business_id'))->where('sales_order_lines.product_id', $record->id);
            }
            $queries[] = $q;
        }
        if (! $customer && $can('purchases.view')) {
            $queries[] = $this->event($business->id, 'purchase_orders', 'purchase_orders.purchased_on', 'purchase', 'purchase_orders.reference', "COALESCE(suppliers.name,purchase_orders.vendor_name,'Purchase')", 'purchase_orders.quantity', $can('finance.view') || $can('purchases.create') ? 'purchase_orders.estimated_total' : 'NULL', 'purchase_orders.currency', 'purchase_orders.status')->leftJoin('suppliers', fn ($j) => $j->on('suppliers.id', '=', 'purchase_orders.supplier_id')->on('suppliers.business_id', '=', 'purchase_orders.business_id'))->where('purchase_orders.product_id', $record->id)->whereNull('purchase_orders.deleted_at');
        }
        if (! $customer && $can('receipts.view')) {
            $queries[] = $this->event($business->id, 'goods_receipts', 'goods_receipts.received_on', 'receiving', 'goods_receipts.reference', "COALESCE(suppliers.name,purchase_orders.vendor_name,'Goods received')", 'goods_receipts.accepted_quantity')->join('purchase_orders', fn ($j) => $j->on('purchase_orders.id', '=', 'goods_receipts.purchase_order_id')->on('purchase_orders.business_id', '=', 'goods_receipts.business_id'))->leftJoin('suppliers', fn ($j) => $j->on('suppliers.id', '=', 'purchase_orders.supplier_id')->on('suppliers.business_id', '=', 'purchase_orders.business_id'))->where('purchase_orders.product_id', $record->id);
        }
        if (! $customer && $can('receipts.view')) {
            $queries[] = $this->event($business->id, 'goods_receipts', 'goods_receipts.received_on', 'quality', 'goods_receipts.reference', "'Rejected at receiving'", 'goods_receipts.rejected_quantity', 'NULL', 'NULL', "'rejected'")->join('purchase_orders', fn ($j) => $j->on('purchase_orders.id', '=', 'goods_receipts.purchase_order_id')->on('purchase_orders.business_id', '=', 'goods_receipts.business_id'))->where('purchase_orders.product_id', $record->id)->where('goods_receipts.rejected_quantity', '>', 0);
        }
        if (! $customer && $can('stock.view')) {
            $queries[] = $this->event($business->id, 'stock_movements', 'stock_movements.occurred_on', 'stock', 'stock_batches.batch_code', 'stock_movements.movement_type', 'stock_movements.quantity')->join('stock_batches', fn ($j) => $j->on('stock_batches.id', '=', 'stock_movements.batch_id')->on('stock_batches.business_id', '=', 'stock_movements.business_id'))->where('stock_batches.product_id', $record->id);
            $queries[] = $this->event($business->id, 'stock_quality_events', 'stock_quality_events.created_at', 'quality', 'stock_batches.batch_code', 'stock_quality_events.reason', 'NULL', 'NULL', 'NULL', 'stock_quality_events.action')->join('stock_batches', fn ($j) => $j->on('stock_batches.id', '=', 'stock_quality_events.batch_id')->on('stock_batches.business_id', '=', 'stock_quality_events.business_id'))->where('stock_batches.product_id', $record->id);
        }
        if ($can('stock.view') && (! $customer || $can('customers.view'))) {
            $q = $this->event($business->id, 'stock_exception_cases', 'stock_exception_cases.event_on', 'return', 'stock_exception_cases.reference', 'stock_exception_cases.kind', 'stock_exception_cases.quantity', 'NULL', 'NULL', 'stock_exception_cases.status');
            if ($customer) {
                $q->join('export_shipments', fn ($j) => $j->on('export_shipments.id', '=', 'stock_exception_cases.shipment_id')->on('export_shipments.business_id', '=', 'stock_exception_cases.business_id'))->where('export_shipments.customer_id', $record->id);
            } else {
                $q->join('stock_batches', fn ($j) => $j->on('stock_batches.id', '=', 'stock_exception_cases.batch_id')->on('stock_batches.business_id', '=', 'stock_exception_cases.business_id'))->where('stock_batches.product_id', $record->id);
            }
            $queries[] = $q;
        }
        if (! $customer && $can('packing.view')) {
            $queries[] = $this->event($business->id, 'packing_jobs', 'COALESCE(packing_jobs.completed_at,packing_jobs.created_at)', 'packing', 'packing_jobs.reference', "COALESCE(packing_jobs.packing_type,'Packing job')", 'NULL', 'NULL', 'NULL', 'packing_jobs.status')->where('packing_jobs.product_id', $record->id);
        }
        if ($can('shipments.view')) {
            $shipments = $this->event($business->id, 'shipment_events', 'shipment_events.created_at', 'shipment', 'export_shipments.reference', 'shipment_events.action', 'NULL', 'NULL', 'NULL', 'export_shipments.status')->join('export_shipments', fn ($j) => $j->on('export_shipments.id', '=', 'shipment_events.shipment_id')->on('export_shipments.business_id', '=', 'shipment_events.business_id'));
            if ($customer) {
                $shipments->where('export_shipments.customer_id', $record->id);
            } else {
                $shipments->whereExists(fn ($q) => $q->selectRaw('1')->from('shipment_cartons as sc')->join('cartons as c', fn ($j) => $j->on('c.id', '=', 'sc.carton_id')->on('c.business_id', '=', 'sc.business_id'))->join('packing_jobs as pk', fn ($j) => $j->on('pk.id', '=', 'c.packing_job_id')->on('pk.business_id', '=', 'c.business_id'))->whereColumn('sc.shipment_id', 'export_shipments.id')->whereColumn('sc.business_id', 'export_shipments.business_id')->where('pk.product_id', $record->id));
            }$queries[] = $shipments;
        }
        if (! $customer && $can('finance.view')) {
            $invoices = $this->event($business->id, 'commercial_invoices', 'commercial_invoices.issued_at', 'invoice', 'commercial_invoices.reference', "'Invoice activity'", 'NULL', 'NULL', 'commercial_invoices.currency', 'commercial_invoices.posting_status');
            if (DB::connection()->getDriverName() === 'sqlite') {
                $invoices->whereExists(fn ($q) => $q->selectRaw('1')->fromRaw("json_each(commercial_invoices.data_snapshot, '$.lines') as product_lines")->whereRaw("CAST(json_extract(product_lines.value, '$.product_id') AS INTEGER) = ?", [$record->id]));
            } else {
                $invoices->whereJsonContains('commercial_invoices.data_snapshot->lines', ['product_id' => $record->id]);
            }
            $queries[] = $invoices;
        }
        if ($customer && $can('finance.view')) {
            $queries[] = $this->event($business->id, 'commercial_invoices', 'commercial_invoices.issued_at', 'invoice', 'commercial_invoices.reference', "'Sales invoice'", 'NULL', 'commercial_invoices.grand_total', 'commercial_invoices.currency', 'commercial_invoices.posting_status')->where('commercial_invoices.customer_id', $record->id);
            $queries[] = $this->event($business->id, 'finance_payments', 'finance_payments.payment_on', 'payment', 'finance_payments.reference', "'Invoice payment'", 'NULL', 'finance_payments.amount', 'finance_payments.currency', 'finance_payments.status')->join('commercial_invoices', fn ($j) => $j->on('commercial_invoices.id', '=', 'finance_payments.commercial_invoice_id')->on('commercial_invoices.business_id', '=', 'finance_payments.business_id'))->where('commercial_invoices.customer_id', $record->id);
            $queries[] = $this->event($business->id, 'finance_operations', 'finance_operations.event_on', 'payment', 'finance_operations.reference', 'finance_operations.kind', 'NULL', 'finance_operations.amount', 'finance_operations.currency', 'finance_operations.posting_status')->where('finance_operations.customer_id', $record->id)->whereIn('finance_operations.kind', ['customer_deposit', 'customer_refund', 'customer_apply']);
            $queries[] = $this->event($business->id, 'finance_credit_notes', 'COALESCE(finance_credit_notes.issued_at,finance_credit_notes.created_at)', 'credit', 'finance_credit_notes.reference', 'finance_credit_notes.reason', 'NULL', 'finance_credit_notes.amount', 'finance_credit_notes.currency', 'finance_credit_notes.posting_status')->join('commercial_invoices', fn ($j) => $j->on('commercial_invoices.id', '=', 'finance_credit_notes.commercial_invoice_id')->on('commercial_invoices.business_id', '=', 'finance_credit_notes.business_id'))->where('commercial_invoices.customer_id', $record->id);
        }
        $union = array_shift($queries) ?? $this->event($business->id, 'products', 'products.created_at', 'product', 'products.sku', 'products.name')->whereRaw('1=0');
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 'activities')->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('activity_on', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('activity_on', '<=', $v))->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->orderBy('activity_on', ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderBy('kind')->orderBy('record_id')->paginate(30)->withQueryString();
    }

    public function customerMetrics(Request $r, Customer $customer, array $filters): array
    {
        $business = $r->attributes->get('activeBusiness');
        $access = app(BusinessPermissions::class);
        $member = $r->attributes->get('membership');
        $metrics = [];
        $range = fn ($q, $date) => $q->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate($date, '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate($date, '<=', $v));
        if ($access->allows($member, 'sales.orders.view')) {
            $orders = $range(SalesOrder::query()->where('business_id', $business->id)->where('customer_id', $customer->id), 'created_at');
            $metrics['Confirmed orders'] = (clone $orders)->whereNotNull('confirmed_at')->where('status', '!=', 'cancelled')->count();
            $metrics['Open orders'] = (clone $orders)->where('status', 'confirmed')->count();
        }
        if ($access->allows($member, 'finance.view')) {
            $balances = [];
            $unpaid = 0;
            foreach ($range(CommercialInvoice::query()->where('business_id', $business->id)->where('customer_id', $customer->id)->where('posting_status', 'posted'), 'issued_at')->orderBy('id')->lazyById(100) as $invoice) {
                $balance = app(FinanceDocuments::class)->outstanding($invoice, false);
                if ($balance->isGreaterThan(0)) {
                    $unpaid++;
                    $balances[$invoice->currency] = ($balances[$invoice->currency] ?? BigDecimal::zero())->plus($balance);
                }
            }
            $metrics['Unpaid invoices'] = $unpaid;
            foreach ($balances as $currency => $amount) {
                $metrics['Outstanding · '.$currency] = (string) $amount->toScale(2, RoundingMode::HalfUp);
            }
            $payments = $range(FinancePayment::query()->where('business_id', $business->id)->where('status', 'posted')->whereHas('invoice', fn ($q) => $q->where('customer_id', $customer->id)), 'payment_on');
            $totals = [];
            foreach ($payments->orderBy('id')->lazyById(100) as $p) {
                $totals[$p->currency] = ($totals[$p->currency] ?? BigDecimal::zero())->plus($p->amount);
            }foreach ($totals as $c => $a) {
                $metrics['Posted invoice payments · '.$c] = (string) $a->toScale(2);
            }
        }

        return $metrics;
    }

    public function productMetrics(Request $r, Product $product, array $filters): array
    {
        $b = $r->attributes->get('activeBusiness');
        $access = app(BusinessPermissions::class);
        $member = $r->attributes->get('membership');
        $metrics = [];
        $range = fn ($q, $date) => $q->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate($date, '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate($date, '<=', $v));
        if ($access->allows($member, 'stock.view')) {
            $stock = DB::table('stock_movements')->join('stock_batches', fn ($j) => $j->on('stock_batches.id', '=', 'stock_movements.batch_id')->on('stock_batches.business_id', '=', 'stock_movements.business_id'))->where('stock_movements.business_id', $b->id)->where('stock_batches.product_id', $product->id);
            $metrics['Current unpacked · '.$product->base_unit] = (string) ($stock->sum('stock_movements.quantity') ?? 0);
            $metrics['Quality holds'] = StockBatch::query()->where('business_id', $b->id)->where('product_id', $product->id)->where('is_quarantined', true)->count();
        }
        if ($access->allows($member, 'sales.orders.view')) {
            $metrics['Orders in period'] = $range(SalesOrder::query()->where('business_id', $b->id)->whereHas('lines', fn ($q) => $q->where('product_id', $product->id)), 'created_at')->count();
        }
        if ($access->allows($member, 'finance.reports.view')) {
            $purchaseTotals = [];
            foreach ($range(PurchaseOrder::query()->where('business_id', $b->id)->where('product_id', $product->id), 'purchased_on')->orderBy('id')->lazyById(100) as $p) {
                $purchaseTotals[$p->currency] = ($purchaseTotals[$p->currency] ?? BigDecimal::zero())->plus($p->estimated_total ?? '0');
            }foreach ($purchaseTotals as $c => $a) {
                $metrics['Purchases ordered · '.$c] = (string) $a->toScale(2);
            }
            $revenue = BigDecimal::zero();
            $cost = BigDecimal::zero();
            $pending = 0;
            $matched = 0;
            $invoices = $range(CommercialInvoice::query()->where('business_id', $b->id)->whereIn('posting_status', ['posted', 'credited'])->whereHas('order.lines', fn ($q) => $q->where('product_id', $product->id)), 'issued_at');
            $batchIds = StockBatch::query()->where('business_id', $b->id)->where('product_id', $product->id)->pluck('id')->all();
            foreach ($invoices->orderBy('id')->lazyById(100) as $invoice) {
                $all = BigDecimal::zero();
                $part = BigDecimal::zero();
                foreach ($invoice->data_snapshot['lines'] ?? [] as $line) {
                    $value = BigDecimal::of((string) ($line['line_total'] ?? '0'));
                    $all = $all->plus($value);
                    if ((int) ($line['product_id'] ?? 0) === $product->id) {
                        $part = $part->plus($value);
                    }
                }
                if (! $all->isGreaterThan(0) || ! $part->isGreaterThan(0)) {
                    continue;
                }
                $journal = DB::table('finance_journal_lines as l')->join('finance_journals as j', 'j.id', '=', 'l.journal_id')->join('finance_accounts as a', 'a.id', '=', 'l.account_id')->where('j.business_id', $b->id)->where('l.business_id', $b->id)->where('a.business_id', $b->id)->where('a.system_key', 'export_sales')->where(function ($q) use ($invoice, $b) {
                    $q->where(fn ($q) => $q->where('j.source_type', 'commercial_invoice')->where('j.source_id', $invoice->id))->orWhere(fn ($q) => $q->where('j.source_type', 'credit_note')->whereIn('j.source_id', DB::table('finance_credit_notes')->where('business_id', $b->id)->where('commercial_invoice_id', $invoice->id)->where('posting_status', 'posted')->select('id')));
                    $q->orWhere(fn ($q) => $q->where('j.source_type', 'balance_adjustment')->whereIn('j.source_id', DB::table('finance_balance_adjustments')->where('business_id', $b->id)->where('commercial_invoice_id', $invoice->id)->where('posting_status', 'posted')->select('id')));
                    $creditFlows = DB::table('finance_trade_credits')->where('business_id', $b->id)->where('commercial_invoice_id', $invoice->id)->select('workflow_id');
                    $q->orWhere(fn ($q) => $q->where('j.source_type', 'finance_workflow')->where(function ($q) use ($creditFlows, $b) {
                        $q->whereIn('j.source_id', clone $creditFlows)->orWhereIn('j.source_id', DB::table('finance_workflow_corrections')->where('business_id', $b->id)->whereIn('original_workflow_id', clone $creditFlows)->select('workflow_id'));
                    }));
                });
                $postedRevenue = BigDecimal::zero();
                foreach ($journal->orderBy('l.id')->get(['l.base_credit', 'l.base_debit']) as $line) {
                    $postedRevenue = $postedRevenue->plus((string) $line->base_credit)->minus((string) $line->base_debit);
                }
                $net = $postedRevenue->multipliedBy($part)->dividedBy($all, 2, RoundingMode::HalfUp);
                $revenue = $revenue->plus($net);
                $relief = FinanceInventoryRelief::query()->where('business_id', $b->id)->where('shipment_id', $invoice->shipment_id)->where('kind', 'cogs')->where('posting_status', 'posted')->first();
                if (! $relief) {
                    $pending++;

                    continue;
                }$matched++;
                foreach ($relief->data_snapshot['lines'] ?? [] as $line) {
                    if (in_array((int) $line['batch_id'], $batchIds, true)) {
                        $cost = $cost->plus((string) ($line['base_cost'] ?? '0'));
                    }
                }
            }
            $metrics['Net sales · '.$b->base_currency] = (string) $revenue->toScale(2);
            $metrics['Recorded cost of sales · '.$b->base_currency] = (string) $cost->toScale(2);
            $metrics['Gross profit · '.$b->base_currency] = $pending ? 'Awaiting '.$pending.' stock valuations' : (string) $revenue->minus($cost)->toScale(2);
        }

        return $metrics;
    }
}
