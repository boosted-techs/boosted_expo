<?php

namespace App\Http\Controllers;

use App\Models\Carton;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\SalesOrder;
use App\Services\SalesFulfillment;
use Illuminate\Support\Facades\DB;
use App\Services\ShipmentOperations;
use App\Support\BusinessPermissions;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExportShipmentController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission
        ),403);
    }

    private function own(Request $request, int $shipment): ExportShipment
    {
        return ExportShipment::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($shipment);
    }

    private function payload(Request $request): array
    {
        $request->merge([
            'origin_country'=>strtoupper(trim((string)$request->input('origin_country'))),
            'destination_country'=>strtoupper(trim((string)$request->input('destination_country'))),
            'air_waybill'=>trim((string)$request->input('air_waybill')) ?: null,
            'flight_number'=>trim((string)$request->input('flight_number')) ?: null,
        ]);
        $data=$request->validate([
            'customer_id'=>['required','integer'],
            'origin_country'=>['required','alpha','size:2'],
            'destination_country'=>['required','alpha','size:2'],
            'origin_airport'=>['nullable','string','max:90'],
            'destination_airport'=>['nullable','string','max:90'],
            'carrier'=>['nullable','string','max:150'],
            'flight_number'=>['nullable','string','max:60'],
            'air_waybill'=>['nullable','string','max:80','regex:/^[A-Za-z0-9\- ]+$/'],
            'scheduled_departure_at'=>['nullable','date'],
            'estimated_arrival_at'=>['nullable','date','after_or_equal:scheduled_departure_at'],
            'incoterm'=>['nullable',Rule::in(['EXW','FCA','CPT','CIP','DAP','DPU','DDP'])],
            'notes'=>['nullable','string','max:2500'],
        ]);
        $business=$request->attributes->get('activeBusiness');
        Customer::query()->where('business_id',$business->id)
            ->where('active',true)->findOrFail($data['customer_id']);
        foreach (['scheduled_departure_at','estimated_arrival_at'] as $key) {
            if (!empty($data[$key])) $data[$key]=Carbon::parse($data[$key],$business->timezone)
                ->utc()->format('Y-m-d H:i:s');
        }
        return $data;
    }

    public function index(Request $request): View
    {
        $this->check($request,'shipments.view');
        $businessId=$request->attributes->get('activeBusiness')->id;
        $shipments=ExportShipment::query()->where('business_id',$businessId)
            ->with('customer')->withCount('allocations')
            ->latest()->paginate(20);
        $canManage=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'shipments.manage'
        );
        return view('shipments.index',compact('shipments','canManage'));
    }

    public function create(Request $request): View
    {
        $this->check($request,'shipments.manage');
        $business=$request->attributes->get('activeBusiness');
        $customers=Customer::query()->where('business_id',$business->id)->where('active',true)
            ->orderBy('name')->get();
        $creationKey=(string)Str::uuid();
        $orders=SalesOrder::query()->where('business_id',$business->id)->where('status','confirmed')->with('customer')->latest()->get();
        $prefillOrderId=(int)$request->query('sales_order_id',0);
        $prefillCustomerId=(int)$request->query('customer_id',0);
        if($prefillOrderId && $orders->firstWhere('id',$prefillOrderId)) {
            $prefillCustomerId=$orders->firstWhere('id',$prefillOrderId)->customer_id;
        }
        return view('shipments.create',compact('customers','business','creationKey','orders','prefillOrderId','prefillCustomerId'));
    }

    public function store(Request $request, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $data=$this->payload($request);
        $key=$request->validate(['creation_key'=>['required','uuid']]);
        $order=$request->validate(['sales_order_id'=>['nullable','integer']]);
        $businessId=$request->attributes->get('activeBusiness')->id;
        $shipment=DB::transaction(function () use($request,$operations,$data,$key,$order,$businessId) {
            $shipment=$operations->create($businessId,[...$data,...$key],$request->user()->id);
            if(!empty($order['sales_order_id'])) {
                $sale=SalesOrder::query()->where('business_id',$businessId)
                    ->findOrFail($order['sales_order_id']);
                app(SalesFulfillment::class)->linkShipment($shipment,$sale,$request->user()->id);
            }
            return $shipment;
        });
        return redirect()->route('shipments.show',$shipment->id)
            ->with('status','Shipment draft saved. Allocate completed cartons before confirming dispatch.');
    }

    public function edit(Request $request, int $shipment): View
    {
        $this->check($request,'shipments.manage');
        $shipment=$this->own($request,$shipment);
        abort_unless($shipment->status==='draft',422,'Only a draft shipment can be edited.');
        $customers=Customer::query()->where('business_id',$shipment->business_id)
            ->where('active',true)->orderBy('name')->get();
        return view('shipments.edit',compact('shipment','customers'));
    }

    public function update(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $shipment=$this->own($request,$shipment);
        $operations->updateDraft($shipment,$this->payload($request),$request->user()->id);
        return redirect()->route('shipments.show',$shipment->id)->with('status','Shipment details saved.');
    }

    public function show(Request $request, int $shipment): View
    {
        $this->check($request,'shipments.view');
        $shipment=$this->own($request,$shipment)->load([
            'customer','salesOrder','commercialInvoice','allocations.carton.packingJob.product',
            'allocations.carton.contents.batch','events.actor','documents.template'
        ]);
        $businessId=$shipment->business_id;
        $available=collect();
        $canManage=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'shipments.manage'
        );
        $canDispatch=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'shipments.dispatch'
        );
        $canGenerate=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'documents.generate'
        );
        $canViewDocs=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'documents.view'
        );
        $canInvoice=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'invoices.issue'
        );
        $canSeeInvoice=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'invoices.view'
        );
        $clearanceChecks=\App\Models\ExportClearanceCheck::query()
            ->where('business_id',$businessId)->where('shipment_id',$shipment->id)
            ->orderByDesc('id')->limit(35)->get();
        $providerCarrierEvents=\App\Models\ExternalProviderEvent::query()
            ->where('business_id',$businessId)->where('shipment_id',$shipment->id)
            ->where('kind','carrier')->orderByDesc('id')->limit(20)->get();
        $clearanceKinds=\App\Services\ExportClearanceRegister::TYPES;
        $canCheckClearance=in_array(
            $request->attributes->get('membership')->role,['owner','admin'],true);
        $approvalService=app(\App\Services\ShipmentDispatchApproval::class);
        $approvalRequired=$approvalService->required($businessId);
        $dispatchReview=$approvalRequired && $shipment->status==='ready'
            ?$approvalService->latest($shipment):null;
        $canReviewDispatch=in_array(
            $request->attributes->get('membership')->role,['owner','admin'],true);
        if ($canManage && $shipment->status==='draft') {
            $search=trim((string)$request->query('carton_search',''));
            $available=Carton::query()->where('business_id',$businessId)
                ->when($search!=='',fn($q)=>$q->where('carton_code','like','%'.mb_substr($search,0,100).'%'))
                ->where('status','packed')
                ->whereHas('packingJob',fn ($q)=>$q->where('status','completed'))
                ->whereDoesntHave('shipmentAllocation')
                ->whereDoesntHave('contents.batch',
                    fn($q)=>$q->where('is_quarantined',true))
                ->with('packingJob.product')
                ->orderBy('id')->limit(100)->get();
        }
        $documentTemplates=\App\Models\ExportDocumentTemplate::query()
            ->where('business_id',$businessId)->where('active',true)
            ->orderBy('type')->orderBy('name')->get();
        return view('shipments.show',compact(
            'shipment','available','canManage','canDispatch','canGenerate',
            'canViewDocs','documentTemplates','canInvoice','canSeeInvoice',
            'approvalRequired','dispatchReview','canReviewDispatch',
            'clearanceChecks','providerCarrierEvents','clearanceKinds','canCheckClearance'
        ));
    }

    public function allocate(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $ids=$request->validate([
            'carton_ids'=>['required','array','min:1','max:50'],
            'carton_ids.*'=>['required','integer','distinct'],
        ]);
        $shipment=$this->own($request,$shipment);
        $added=$operations->allocate($shipment,$ids['carton_ids'],$request->user()->id);
        return back()->with('status',$added.' carton(s) allocated. No stock has been deducted again.');
    }

    public function release(Request $request, int $shipment, int $carton,
        ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $operations->release($this->own($request,$shipment),$carton,$request->user()->id);
        return back()->with('status','Carton returned to available packed stock.');
    }

    public function ready(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $operations->ready($this->own($request,$shipment),$request->user()->id);
        return back()->with('status','Shipment marked ready. Document generation is now available.');
    }

    public function reopen(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $operations->reopen($this->own($request,$shipment),$request->user()->id);
        return back()->with('status','Ready shipment reopened. Earlier paperwork may now be outdated.');
    }

    public function dispatch(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.dispatch');
        $data=$request->validate([
            'actual_departure_at'=>['required','date'],
            'confirm_dispatch'=>['required','accepted'],
        ]);
        $utc=Carbon::parse($data['actual_departure_at'],
            $request->attributes->get('activeBusiness')->timezone)->utc()->format('Y-m-d H:i:s');
        $operations->dispatch($this->own($request,$shipment),$utc,$request->user()->id);
        return back()->with('status','Shipment manually recorded as dispatched. Allocated cartons are now shipped.');
    }

    public function arrive(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.dispatch');
        $data=$request->validate([
            'actual_arrival_at'=>['required','date'],
            'confirm_arrival'=>['required','accepted'],
        ]);
        $utc=Carbon::parse($data['actual_arrival_at'],
            $request->attributes->get('activeBusiness')->timezone)->utc()->format('Y-m-d H:i:s');
        $operations->arrive($this->own($request,$shipment),$utc,$request->user()->id);
        return back()->with('status','Shipment manually marked delivered.');
    }

    public function cancel(Request $request, int $shipment, ShipmentOperations $operations): RedirectResponse
    {
        $this->check($request,'shipments.manage');
        $request->validate(['confirm_cancel'=>['required','accepted']]);
        $operations->cancel($this->own($request,$shipment),$request->user()->id);
        return redirect()->route('shipments.show',$shipment)
            ->with('status','Shipment cancelled; unshipped cartons returned to packed stock.');
    }
}
