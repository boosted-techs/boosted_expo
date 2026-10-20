<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ProcurementMath;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'),$permission),403);
    }

    private function own(Request $request, int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->where('business_id',$request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request,'purchases.view');
        $business = $request->attributes->get('activeBusiness');
        $purchases = PurchaseOrder::query()->where('business_id',$business->id)
            ->with(['product','supplier','receipts'])->latest()->paginate(20);
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'purchases.create');
        $canReceive = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'receipts.post');

        return view('purchases.index',compact('purchases','canCreate','canReceive'));
    }

    public function create(Request $request): View
    {
        $this->check($request,'purchases.create');
        $business = $request->attributes->get('activeBusiness');
        $products = Product::query()->where('business_id',$business->id)->where('active',true)
            ->orderBy('name')->get();
        $suppliers = Supplier::query()->where('business_id',$business->id)->where('active',true)
            ->orderBy('name')->get();
        $formKey = (string) Str::uuid();

        return view('purchases.create',compact('products','suppliers','formKey','business'));
    }

    public function store(Request $request, ProcurementMath $math): RedirectResponse
    {
        $this->check($request,'purchases.create');
        $data = $request->validate([
            'kind'=>['required',Rule::in(['market','walk_in','supplier'])],
            'product_id'=>['required','integer'],
            'supplier_id'=>['nullable','integer'],
            'vendor_name'=>['nullable','string','max:160'],
            'vendor_phone'=>['nullable','string','max:50'],
            'market_location'=>['nullable','string','max:150'],
            'purchased_on'=>['required','date'],
            'quantity'=>['required','numeric','gt:0','decimal:0,3','max:999999999'],
            'unit_price'=>['required','numeric','gt:0','decimal:0,4','max:99999999999'],
            'currency'=>['required','alpha','size:3'],
            'notes'=>['nullable','string','max:2000'],
            'idempotency_key'=>['required','uuid'],
        ]);

        $business = $request->attributes->get('activeBusiness');
        Product::query()->where('business_id',$business->id)->where('active',true)
            ->findOrFail($data['product_id']);
        if ($data['kind'] === 'supplier') {
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages(['supplier_id'=>'Choose a registered supplier.']);
            }
            Supplier::query()->where('business_id',$business->id)->where('active',true)
                ->findOrFail($data['supplier_id']);
        } else {
            $data['supplier_id'] = null;
        }

        $order = DB::transaction(function () use ($data,$business,$request,$math) {
            $existing = PurchaseOrder::query()->where('business_id',$business->id)
                ->where('idempotency_key',$data['idempotency_key'])->first();
            if ($existing) return $existing;

            return PurchaseOrder::create([
                ...$data, 'business_id'=>$business->id,
                'reference'=>'PO-'.Str::upper((string) Str::ulid()),
                'currency'=>strtoupper($data['currency']),
                'estimated_total'=>$math->amount($data['unit_price'],$data['quantity']),
                'created_by'=>$request->user()->id,
                'status'=>'ordered',
            ]);
        });

        return redirect()->route('purchases.show',$order->id)
            ->with('status','Purchase recorded. Goods will enter stock only after receiving.');
    }

    public function show(Request $request, int $purchase): View
    {
        $this->check($request,'purchases.view');
        $purchase = $this->own($request,$purchase)->load(['product','supplier','receipts.batch']);
        $canReceive = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'receipts.post');
        $remaining = app(ProcurementMath::class)->decimal($purchase->quantity)->minus(
            app(ProcurementMath::class)->sum($purchase->receipts->pluck('received_quantity')->all())
        );

        return view('purchases.show',compact('purchase','remaining','canReceive'));
    }
}