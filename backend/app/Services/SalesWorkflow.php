<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationLine;
use App\Models\SalesRequest;
use App\Models\SalesRequestItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesWorkflow
{
    public function createRequest(int $businessId, array $data, int $userId): SalesRequest
    {
        return DB::transaction(function() use($businessId,$data,$userId) {
            Customer::query()->where('business_id',$businessId)->where('active',true)
                ->findOrFail($data['customer_id']);
            $products=Product::query()->where('business_id',$businessId)->where('active',true)
                ->whereIn('id',array_column($data['items'],'product_id'))->get()->keyBy('id');
            if($products->count()!==count($data['items'])) {
                throw ValidationException::withMessages(['items'=>'Select valid, distinct products from this business.']);
            }
            $request=SalesRequest::create([
                'business_id'=>$businessId,
                'reference'=>'CRQ-'.Str::upper((string)Str::ulid()),
                'customer_id'=>$data['customer_id'],'required_on'=>$data['required_on']??null,
                'notes'=>$data['notes']??null,'status'=>'open','created_by'=>$userId,
            ]);
            foreach($data['items'] as $line) {
                SalesRequestItem::create([
                    'business_id'=>$businessId,'sales_request_id'=>$request->id,
                    'product_id'=>$line['product_id'],'quantity'=>$line['quantity'],
                ]);
            }
            return $request;
        });
    }

    public function draft(int $businessId,int $requestId,int $userId): SalesQuotation
    {
        return DB::transaction(function () use($businessId,$requestId,$userId) {
            $request=SalesRequest::query()->where('business_id',$businessId)
                ->lockForUpdate()->findOrFail($requestId);
            abort_unless(in_array($request->status,['open','quoted'],true),422,
                'A confirmed customer request cannot be revised.');
            if($request->order()->exists()) abort(422,'This customer request has a confirmed order.');
            $latest=$request->quotations()->orderByDesc('revision')->first();
            if($latest?->status==='draft') return $latest;
            $revision=($latest?->revision??0)+1;
            $quote=SalesQuotation::create([
                'business_id'=>$businessId,'sales_request_id'=>$request->id,
                'reference'=>$request->reference.'-Q'.str_pad((string)$revision,3,'0',STR_PAD_LEFT),
                'revision'=>$revision,'status'=>'draft',
                'currency'=>$latest?->currency??$request->customer->currency,
                'incoterm'=>$latest?->incoterm,
                'valid_until'=>$latest?->valid_until,
                'discount_percent'=>$latest?->discount_percent??0,
                'tax_percent'=>$latest?->tax_percent??0,
                'subtotal'=>'0','discount_amount'=>'0','tax_amount'=>'0','grand_total'=>'0',
                'notes'=>$latest?->notes,'created_by'=>$userId,
            ]);
            $prior=$latest?->lines->keyBy('product_id');
            foreach($request->items as $item) {
                $saved=$prior?->get($item->product_id);
                SalesQuotationLine::create([
                    'business_id'=>$businessId,'sales_quotation_id'=>$quote->id,
                    'product_id'=>$item->product_id,
                    'quantity'=>$saved?->quantity??$item->quantity,
                    'unit_price'=>$saved?->unit_price??'0',
                    'line_total'=>$saved?->line_total??'0',
                    'product_name'=>$saved?->product_name??$item->product->name,
                    'product_sku'=>$saved?->product_sku??$item->product->sku,
                    'unit'=>$saved?->unit??$item->product->base_unit,
                ]);
            }
            return $quote;
        });
    }

    public function updateDraft(SalesQuotation $quote,array $data): SalesQuotation
    {
        return DB::transaction(function() use($quote,$data) {
            $quote=SalesQuotation::query()->where('business_id',$quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            abort_unless($quote->status==='draft',422,'Only draft quotations can be edited.');
            $request=$quote->salesRequest()->with('items')->firstOrFail();
            $expected=$request->items->keyBy('product_id');
            $seen=[];
            foreach($data['items'] as $line) {
                $id=(int)$line['product_id'];
                if(isset($seen[$id]) || ! $expected->has($id)) {
                    throw ValidationException::withMessages(['items'=>'Each offered product must match the customer request exactly once.']);
                }
                $seen[$id]=true;
                if(BigDecimal::of((string)$line['quantity'])->isGreaterThan(
                    BigDecimal::of((string)$expected[$id]->quantity))) {
                    throw ValidationException::withMessages(['items'=>'An offered quantity exceeds the requested quantity.']);
                }
            }
            if(count($seen)!==$expected->count()) {
                throw ValidationException::withMessages(['items'=>'Provide a price and quantity for every requested product.']);
            }
            $totals=app(SalesPricing::class)->totals($data['items'],
                (string)($data['discount_percent']??0),(string)($data['tax_percent']??0));
            foreach($totals['lines'] as $line) {
                SalesQuotationLine::query()->where('business_id',$quote->business_id)
                    ->where('sales_quotation_id',$quote->id)
                    ->where('product_id',$line['product_id'])
                    ->update([
                        'quantity'=>$line['quantity'],'unit_price'=>$line['unit_price'],
                        'line_total'=>$line['line_total'],
                    ]);
            }
            $quote->update([
                'currency'=>strtoupper($data['currency']),
                'incoterm'=>$data['incoterm']??null,
                'valid_until'=>$data['valid_until']??null,
                'discount_percent'=>$data['discount_percent']??0,
                'tax_percent'=>$data['tax_percent']??0,
                'notes'=>$data['notes']??null,
                'subtotal'=>$totals['subtotal'],
                'discount_amount'=>$totals['discount_amount'],
                'tax_amount'=>$totals['tax_amount'],
                'grand_total'=>$totals['grand_total'],
            ]);
            return $quote->refresh();
        });
    }

    public function issue(SalesQuotation $quote,int $userId): void
    {
        DB::transaction(function() use($quote,$userId) {
            $request=SalesRequest::query()->where('business_id',$quote->business_id)
                ->lockForUpdate()->findOrFail($quote->sales_request_id);
            $quote=SalesQuotation::query()->where('business_id',$quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            abort_unless($quote->status==='draft' && $request->status!=='confirmed',422,
                'This quotation cannot be issued.');
            $latest=$request->quotations()->max('revision');
            abort_unless($latest===$quote->revision,422,'A newer quotation revision already exists.');
            if($quote->valid_until && $quote->valid_until->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['valid_until'=>'Validity date has passed.']);
            }
            foreach($quote->lines as $line) {
                if(BigDecimal::of((string)$line->unit_price)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['items'=>'Every quoted product needs a positive unit price.']);
                }
            }
            $request->quotations()->where('status','issued')->update(['status'=>'superseded']);
            $quote->update(['status'=>'issued','issued_by'=>$userId,'issued_at'=>now()]);
            // Preserve exactly what the buyer was offered, including names and prices.
            $quote->refresh()->load(['lines.product','salesRequest.customer']);
            $exporter=\App\Models\Business::query()->findOrFail($quote->business_id);
            $html=view('sales.quotes.print',['quote'=>$quote,'exporter'=>$exporter->name])->render();
            $quote->update(['html_snapshot'=>$html,'sha256'=>hash('sha256',$html)]);
            $request->update(['status'=>'quoted']);
        });
    }

    public function confirm(SalesQuotation $quote,string $buyerConfirmation,int $userId): SalesOrder
    {
        return DB::transaction(function() use($quote,$buyerConfirmation,$userId) {
            $request=SalesRequest::query()->where('business_id',$quote->business_id)
                ->lockForUpdate()->findOrFail($quote->sales_request_id);
            $quote=SalesQuotation::query()->where('business_id',$quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            $existing=$request->order()->first();
            if($existing) abort(422,'This customer request already has a confirmed order.');
            abort_unless($quote->status==='issued' && $request->status==='quoted',422,
                'Only an issued, current quotation can be confirmed.');
            abort_unless(! $quote->valid_until || $quote->valid_until->endOfDay()->isFuture(),422,
                'The quotation has expired. Issue a new revision.');
            $order=SalesOrder::create([
                'business_id'=>$quote->business_id,'reference'=>'SO-'.Str::upper((string)Str::ulid()),
                'sales_request_id'=>$request->id,'sales_quotation_id'=>$quote->id,
                'customer_id'=>$request->customer_id,'status'=>'confirmed',
                'currency'=>$quote->currency,'incoterm'=>$quote->incoterm,
                'discount_percent'=>$quote->discount_percent,'tax_percent'=>$quote->tax_percent,
                'subtotal'=>$quote->subtotal,'discount_amount'=>$quote->discount_amount,
                'tax_amount'=>$quote->tax_amount,'grand_total'=>$quote->grand_total,
                'buyer_confirmation'=>$buyerConfirmation,'notes'=>$quote->notes,
                'confirmed_by'=>$userId,'confirmed_at'=>now(),
            ]);
            foreach($quote->lines as $line) SalesOrderLine::create([
                'business_id'=>$quote->business_id,'sales_order_id'=>$order->id,
                'product_id'=>$line->product_id,'quantity'=>$line->quantity,
                'unit_price'=>$line->unit_price,'line_total'=>$line->line_total,
                'product_name'=>$line->product_name??$line->product->name,
                'product_sku'=>$line->product_sku??$line->product->sku,
                'unit'=>$line->unit??$line->product->base_unit,
            ]);
            $quote->update(['status'=>'accepted']);
            $request->update(['status'=>'confirmed']);
            return $order;
        });
    }
}
