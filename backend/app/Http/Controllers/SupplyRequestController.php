<?php

namespace App\Http\Controllers;

use App\Mail\SupplierRequestMail;
use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Services\ProcurementMath;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupplyRequestController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function own(Request $request, int $id): SupplyRequest
    {
        return SupplyRequest::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'procurement.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $requests = SupplyRequest::query()->where('business_id', $businessId)
            ->with('product')->withCount('quotes')->latest()->paginate(20);
        $products = Product::query()->where('business_id', $businessId)->where('active', true)->orderBy('name')->get();
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'procurement.create');

        return view('procurement.index', compact('requests','products','canCreate'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'procurement.create');
        $data = $request->validate([
            'product_id'=>['required','integer'],
            'quantity'=>['required','numeric','gt:0','decimal:0,3','max:999999999'],
            'currency'=>['required','alpha','size:3'],
            'needed_on'=>['nullable','date'],
            'response_deadline'=>['required','date','after:now'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $business = $request->attributes->get('activeBusiness');
        Product::query()->where('business_id',$business->id)->where('active',true)
            ->findOrFail($data['product_id']);
        $rfq = SupplyRequest::create([
            ...$data,'business_id'=>$business->id,
            'reference'=>'RFQ-'.Str::upper((string) Str::ulid()),
            'currency'=>strtoupper($data['currency']),
            'status'=>'draft','created_by'=>$request->user()->id,
        ]);

        return redirect()->route('supply-requests.show', $rfq->id)
            ->with('status', 'Supply request saved. Choose suppliers to issue it.');
    }

    public function show(Request $request, int $rfq): View
    {
        $this->check($request,'procurement.view');
        $rfq = $this->own($request,$rfq)->load([
            'product','links.supplier','quotes.supplier','quotes.revisions',
        ]);
        $suppliers = Supplier::query()->where('business_id', $rfq->business_id)
            ->where('active',true)->orderBy('name')->get();
        $purchase = PurchaseOrder::query()->where('business_id', $rfq->business_id)
            ->whereIn('supplier_quote_id', $rfq->quotes->pluck('id'))->first();
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'procurement.create');
        $canAward = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'procurement.award');

        return view('procurement.show', compact('rfq','suppliers','purchase','canCreate','canAward'));
    }

    public function issue(Request $request, int $rfq): RedirectResponse
    {
        $this->check($request,'procurement.create');
        $data = $request->validate([
            'supplier_ids'=>['required','array','min:1','max:20'],
            'supplier_ids.*'=>['required','integer','distinct'],
        ]);
        $ids = $data['supplier_ids'];
        $rfq = $this->own($request,$rfq);
        $suppliers = Supplier::query()->where('business_id',$rfq->business_id)
            ->where('active',true)->whereIn('id',$ids)->get();
        if ($suppliers->count() !== count($ids) || $suppliers->contains(fn ($s) => ! $s->email)) {
            throw ValidationException::withMessages(['supplier_ids'=>'Select active registered suppliers with email addresses.']);
        }
        $links = DB::transaction(function () use ($rfq,$suppliers) {
            $locked = SupplyRequest::query()->where('business_id',$rfq->business_id)
                ->lockForUpdate()->findOrFail($rfq->id);
            abort_unless($locked->status === 'draft' && $locked->response_deadline->isFuture(), 422,
                'This supply request cannot be issued.');
            $result = [];
            foreach ($suppliers as $supplier) {
                $token = Str::random(64);
                $link = SupplierRequestLink::create([
                    'business_id'=>$locked->business_id,'supply_request_id'=>$locked->id,
                    'supplier_id'=>$supplier->id,'token_hash'=>hash('sha256',$token),
                    'expires_at'=>$locked->response_deadline,
                ]);
                $result[] = compact('supplier','token','link');
            }
            $locked->update(['status'=>'sent']);
            return $result;
        });

        $failures = 0;
        foreach ($links as $item) {
            try {
                Mail::to($item['supplier']->email)->send(
                    new SupplierRequestMail($rfq->load('product'), $item['supplier'], $item['token'])
                );
                $item['link']->update(['sent_at'=>now()]);
            } catch (\Throwable $error) {
                report($error);
                $failures++;
            }
        }
        $message = $failures
            ? 'Supply request issued, but '.$failures.' email invitation(s) could not be sent. Check mail settings.'
            : (config('mail.default') === 'log'
                ? 'Supply request issued. Supplier links were written to the local mail log, not delivered.'
                : 'Supply request issued to selected suppliers.');

        return redirect()->route('supply-requests.show',$rfq->id)->with('status',$message);
    }

    public function award(Request $request, int $rfq, int $quote, ProcurementMath $math): RedirectResponse
    {
        $this->check($request,'procurement.award');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $order = DB::transaction(function () use ($businessId,$rfq,$quote,$math,$request) {
            $supply = SupplyRequest::query()->where('business_id',$businessId)->lockForUpdate()->findOrFail($rfq);
            abort_unless($supply->status === 'sent', 422, 'This request cannot be awarded.');
            $offer = SupplierQuote::query()->where('business_id',$businessId)
                ->where('supply_request_id',$supply->id)->where('status','submitted')
                ->findOrFail($quote);
            $order = PurchaseOrder::create([
                'business_id'=>$businessId,
                'reference'=>'PO-'.Str::upper((string) Str::ulid()),
                'kind'=>'supplier','product_id'=>$supply->product_id,
                'supplier_id'=>$offer->supplier_id,'supplier_quote_id'=>$offer->id,
                'purchased_on'=>now()->timezone($supply->created_at->timezone)->toDateString(),
                'quantity'=>$offer->quantity,'unit_price'=>$offer->unit_price,
                'estimated_total'=>$math->amount($offer->unit_price,$offer->quantity),
                'currency'=>$offer->currency,'status'=>'ordered',
                'notes'=>'Awarded from '.$supply->reference,
                'idempotency_key'=>(string) Str::uuid(),'created_by'=>$request->user()->id,
            ]);
            $offer->update(['status'=>'selected']);
            $supply->update(['status'=>'awarded']);
            return $order;
        });

        return redirect()->route('purchases.show',$order->id)
            ->with('status','Supplier selected and purchase order created. Awaiting goods receipt.');
    }
}