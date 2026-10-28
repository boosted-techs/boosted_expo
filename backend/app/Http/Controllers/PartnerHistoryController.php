<?php

namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\PartnerFollowup;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Services\OrderOperations;
use App\Services\PlatformAccess;
use App\Services\ProcurementMath;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerHistoryController extends Controller
{
    public function receiving(Request $request): View|StreamedResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'receipts.view'), 403);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'supplier_id' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'], 'vendor' => ['nullable', 'string', 'max:160'], 'output' => ['nullable', 'in:csv,print']]);
        foreach (['supplier_id' => Supplier::class, 'product_id' => Product::class] as $field => $model) {
            if (! empty($filters[$field])) {
                $model::query()->where('business_id', $b->id)->findOrFail($filters[$field]);
            }
        }
        $query = GoodsReceipt::query()->where('business_id', $b->id)->with('purchaseOrder.product', 'purchaseOrder.supplier', 'batch', 'receiver')
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('received_on', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('received_on', '<=', $v))
            ->whereHas('purchaseOrder', fn ($q) => $q->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))->when($filters['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_name', 'like', '%'.$v.'%')));
        $totals = [];
        foreach ((clone $query)->with('purchaseOrder.product')->orderBy('id')->lazyById(200) as $r) {
            $unit = $r->purchaseOrder->product->base_unit;
            $key = $r->purchaseOrder->product_id;
            $totals[$key] ??= ['product' => $r->purchaseOrder->product->name, 'unit' => $unit, 'accepted' => BigDecimal::zero(), 'rejected' => BigDecimal::zero(), 'receipts' => 0];
            $totals[$key]['accepted'] = $totals[$key]['accepted']->plus((string) $r->accepted_quantity);
            $totals[$key]['rejected'] = $totals[$key]['rejected']->plus((string) $r->rejected_quantity);
            $totals[$key]['receipts']++;
        }
        if (($filters['output'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query, $filters, $b) {
                $out = fopen('php://output', 'w');
                $write = function (array $row) use ($out) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $row), ',', '"', '');
                };
                $write(['Business', $b->code, 'Business date timezone', 'Africa/Kampala', 'Filters', json_encode($filters)]);
                $write(['Receipt', 'Received on', 'Supplier / vendor', 'Product', 'Unit', 'Accepted', 'Rejected', 'Receiver', 'Batch', 'Purchase']);
                foreach ((clone $query)->orderBy('id')->lazyById(200) as $r) {
                    $p = $r->purchaseOrder;
                    $write([$r->reference, $r->received_on->toDateString(), $p->supplier?->name ?? $p->vendor_name, $p->product->name, $p->product->base_unit, $r->accepted_quantity, $r->rejected_quantity, $r->receiver?->name, $r->batch?->batch_code, $p->reference]);
                }fclose($out);
            }, $b->code.'-supplier-receiving.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }
        $receipts = $query->orderByDesc('received_on')->orderByDesc('id')->workspacePaginate(30)->withQueryString();
        $suppliers = Supplier::query()->where('business_id', $b->id)->orderBy('name')->get();
        $products = Product::query()->where('business_id', $b->id)->orderBy('name')->get();

        return view('partners.receiving', compact('b', 'filters', 'totals', 'receipts', 'suppliers', 'products'));
    }

    public function followup(Request $request, string $kind, int $partner): RedirectResponse
    {
        $b = $request->attributes->get('activeBusiness');
        $permission = $kind === 'supplier' ? 'purchases.create' : 'sales.manage';
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
        $record = ($kind === 'supplier' ? Supplier::query() : Customer::query())->where('business_id', $b->id)->findOrFail($partner);
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:150'], 'note' => ['required', 'string', 'min:10', 'max:2000'], 'due_at' => ['required', 'date_format:Y-m-d\TH:i'], 'followup_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,acknowledged,resolved']]);
        DB::transaction(function () use ($b, $record, $data, $kind, $request) {
            app(OrderOperations::class)->lockForWork($b->id, $kind === 'supplier' ? 'procurement' : 'sales');
            app(OrderOperations::class)->person($b->id, (int) $data['staff_person_id']);
            $field = $kind.'_id';
            $followup = empty($data['followup_id']) ? new PartnerFollowup(['business_id' => $b->id, $field => $record->id, 'created_by' => $request->user()->id]) : PartnerFollowup::query()->where('business_id', $b->id)->where($field, $record->id)->findOrFail($data['followup_id']);
            $followup->fill([...collect($data)->except('followup_id')->all(), 'due_at' => Carbon::parse($data['due_at'], 'Africa/Kampala')->utc()])->save();
            app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'partner.followup', $data['note'], [], ['followup_id' => $followup->id, 'status' => $followup->status]);
        });

        return back()->with('status', 'Partner follow-up ownership, deadline and result recorded.');
    }

    public function show(Request $request, string $kind, int $partner): View
    {
        $b = $request->attributes->get('activeBusiness');
        $permissions = app(BusinessPermissions::class);
        abort_unless($permissions->allows($request->attributes->get('membership'), $kind === 'supplier' ? 'suppliers.view' : 'customers.view'), 403);
        $record = ($kind === 'supplier' ? Supplier::query() : Customer::query())->where('business_id', $b->id)->findOrFail($partner);
        $purchases = $kind === 'supplier' ? PurchaseOrder::query()->where('business_id', $b->id)->where('supplier_id', $record->id)->with('product', 'receipts')->latest('id')->workspacePaginate(20) : collect();
        $orders = $kind === 'customer' ? SalesOrder::query()->where('business_id', $b->id)->where('customer_id', $record->id)->latest('id')->workspacePaginate(20) : collect();
        $canMoney = $permissions->allows($request->attributes->get('membership'), 'finance.view');
        $bills = $canMoney ? ($kind === 'supplier' ? FinanceSupplierBill::query()->where('business_id', $b->id)->whereHas('purchase', fn ($q) => $q->where('supplier_id', $record->id))->latest('id')->workspacePaginate(20, ['*'], 'bill_page') : CommercialInvoice::query()->where('business_id', $b->id)->where('customer_id', $record->id)->latest('id')->workspacePaginate(20, ['*'], 'bill_page')) : collect();
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $data['from'] ?? today('Africa/Kampala')->subDays(29)->toDateString();
        $to = $data['to'] ?? today('Africa/Kampala')->toDateString();
        $performance = ['due_promises' => 0, 'on_time' => 0, 'late_or_incomplete' => 0, 'missing_expected_dates' => 0, 'products' => []];
        if ($kind === 'supplier') {
            foreach (PurchaseOrder::query()->where('business_id', $b->id)->where('supplier_id', $record->id)->whereDate('purchased_on', '>=', $from)->whereDate('purchased_on', '<=', $to)->with('receipts', 'product')->orderBy('id')->lazyById(200) as $purchase) {
                if (! $purchase->expected_on) {
                    $performance['missing_expected_dates']++;
                } elseif ($purchase->expected_on->toDateString() <= today('Africa/Kampala')->toDateString()) {
                    $performance['due_promises']++;
                    $received = app(ProcurementMath::class)->sum($purchase->receipts->pluck('received_quantity')->all());
                    $last = $purchase->receipts->max('received_on');
                    if ($received->isEqualTo((string) $purchase->quantity) && $last && $last->toDateString() <= $purchase->expected_on->toDateString()) {
                        $performance['on_time']++;
                    } else {
                        $performance['late_or_incomplete']++;
                    }
                }
                $key = $purchase->product_id;
                $performance['products'][$key] ??= ['name' => $purchase->product->name, 'unit' => $purchase->product->base_unit, 'accepted' => BigDecimal::zero(), 'rejected' => BigDecimal::zero(), 'receipts' => 0];
                foreach ($purchase->receipts as $r) {
                    $performance['products'][$key]['accepted'] = $performance['products'][$key]['accepted']->plus((string) $r->accepted_quantity);
                    $performance['products'][$key]['rejected'] = $performance['products'][$key]['rejected']->plus((string) $r->rejected_quantity);
                    $performance['products'][$key]['receipts']++;
                }
            }
        }
        $followups = PartnerFollowup::query()->where('business_id', $b->id)->where($kind.'_id', $record->id)->with('person')->orderBy('due_at')->workspacePaginate(15, ['*'], 'followup_page');
        $people = StaffPerson::query()->where('business_id', $b->id)->where('active', true)->orderBy('name')->get();
        $canFollowup = $permissions->allows($request->attributes->get('membership'), $kind === 'supplier' ? 'purchases.create' : 'sales.manage');

        return view('partners.show', compact('b', 'kind', 'record', 'purchases', 'orders', 'bills', 'canMoney', 'from', 'to', 'performance', 'followups', 'people', 'canFollowup'));
    }
}
