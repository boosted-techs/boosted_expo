<?php

namespace App\Services;

use App\Models\ExportDocument;
use App\Models\ExportDocumentTemplate;
use App\Models\ExportShipment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExportDocuments
{
    public const COLUMN_LABELS = [
        'carton'=>'Carton Reference',
        'product'=>'Produce',
        'sku'=>'Product Code',
        'grade'=>'Grade',
        'farmer'=>'Farmer / Origin Code',
        'batches'=>'Source Batch',
        'packaging'=>'Packaging',
        'boxes'=>'No. of Boxes',
        'net'=>'Net Weight (kg)',
        'tare'=>'Empty Box (kg)',
        'gross'=>'Gross Weight (kg)',
    ];
    public const HEADER_LABELS = [
        'customer'=>'Consignee',
        'carrier'=>'Carrier / Airline',
        'flight'=>'Flight Number',
        'awb'=>'Air Waybill',
        'route'=>'Export Route',
        'schedule'=>'Scheduled Departure',
        'arrival'=>'Estimated Arrival',
        'incoterm'=>'Incoterm',
    ];
    public const TYPES = ['packing_list'=>'Packing List','dispatch_note'=>'Dispatch Note'];

    public function snapshot(ExportShipment $shipment, ExportDocumentTemplate $template): array
    {
        abort_unless($shipment->business_id === $template->business_id,404);
        $shipment->loadMissing([
            'business','customer','allocations.carton.packingJob.product',
            'allocations.carton.contents.batch',
        ]);
        if ($shipment->allocations->isEmpty()) {
            throw ValidationException::withMessages([
                'document'=>'Allocate at least one carton before issuing documents.',
            ]);
        }

        $net=BigDecimal::zero();
        $gross=BigDecimal::zero();
        $tare=BigDecimal::zero();
        $lines=[];
        $productSummary=[];
        $allocations=$shipment->allocations->sortBy(fn ($a)=>$a->carton?->carton_code);
        foreach ($allocations as $allocation) {
            $carton=$allocation->carton;
            abort_unless($carton && $carton->packingJob?->product,422,
                'An allocated carton has missing product information.');
            $produce=$carton->packingJob->product;
            $batchCodes=$carton->contents->map(fn ($c)=>$c->batch?->batch_code)
                ->filter()->unique()->values()->all();
            $farmerCodes=$carton->contents->map(fn ($c)=>$c->batch?->source_reference)
                ->filter()->unique()->values()->all();
            if ($carton->net_weight_kg === null || $carton->gross_weight_kg === null) {
                throw ValidationException::withMessages([
                    'document'=>'Carton '.$carton->carton_code.' has missing net/gross weights. Confirm both before issuing export paperwork.',
                ]);
            }
            $netValue=BigDecimal::of((string)$carton->net_weight_kg);
            $grossValue=BigDecimal::of((string)$carton->gross_weight_kg);
            $tareValue=$carton->tare_weight_kg === null
                ? $grossValue->minus($netValue)
                : BigDecimal::of((string)$carton->tare_weight_kg);
            if ($netValue->isLessThanOrEqualTo(0) || $tareValue->isLessThan(0) ||
                ! $netValue->plus($tareValue)->isEqualTo($grossValue)) {
                throw ValidationException::withMessages([
                    'document'=>'Carton '.$carton->carton_code.' has inconsistent gross, net or tare weights.',
                ]);
            }
            $line=[
                'carton'=>$carton->carton_code,
                'product'=>$produce->name,
                'sku'=>$produce->sku,
                'grade'=>$carton->grade ?: '—',
                'farmer'=>$farmerCodes ? implode(', ',$farmerCodes) : 'Not recorded',
                'batches'=>$batchCodes ? implode(', ',$batchCodes) : '—',
                'packaging'=>$carton->packaging_type ?: '—',
                'boxes'=>'1',
                'net'=>(string)$netValue->toScale(3),
                'gross'=>(string)$grossValue->toScale(3),
                'tare'=>(string)$tareValue->toScale(3),
            ];
            $lines[]=$line;
            $net=$net->plus($line['net']);
            $gross=$gross->plus($line['gross']);
            $tare=$tare->plus($line['tare']);
            $productKey=$produce->sku;
            if (! isset($productSummary[$productKey])) {
                $productSummary[$productKey]=[
                    'product'=>$produce->name,'sku'=>$produce->sku,
                    'cartons'=>0,'net'=>'0','gross'=>'0',
                ];
            }
            $group=$productSummary[$productKey];
            $group['cartons']++;
            $group['net']=(string)BigDecimal::of($group['net'])->plus($line['net'])->toScale(3);
            $group['gross']=(string)BigDecimal::of($group['gross'])->plus($line['gross'])->toScale(3);
            $productSummary[$productKey]=$group;
        }
        $buyer=$shipment->customer;
        $company=$shipment->business;
        $header=[
            'customer'=>$buyer->name.' ('.$buyer->country_code.')',
            'carrier'=>$shipment->carrier ?: 'Not recorded',
            'flight'=>$shipment->flight_number ?: 'Not recorded',
            'awb'=>$shipment->air_waybill ?: 'Not recorded',
            'route'=>trim(($shipment->origin_airport ?: $shipment->origin_country)
                .' → '.($shipment->destination_airport ?: $shipment->destination_country)),
            'schedule'=>$shipment->scheduled_departure_at?->copy()->timezone($company->timezone)->format('d M Y H:i') ?: 'Not recorded',
            'arrival'=>$shipment->estimated_arrival_at?->copy()->timezone($company->timezone)->format('d M Y H:i') ?: 'Not recorded',
            'incoterm'=>$shipment->incoterm ?: 'Not specified',
        ];

        return [
            'schema_version'=>1,
            'template'=>[
                'name'=>$template->name,'type'=>$template->type,
                'version'=>$template->version,'columns'=>$template->visible_columns,
                'headers'=>$template->header_fields,
                'paper_size'=>$template->paper_size,'orientation'=>$template->orientation,
            ],
            'exporter'=>[
                'name'=>$company->name,'country'=>$company->country_code,'code'=>$company->code,
            ],
            'consignee'=>[
                'name'=>$buyer->name,'code'=>$buyer->customer_code,
                'country'=>$buyer->country_code,'city'=>$buyer->city,
                'email'=>$buyer->email,
            ],
            'shipment'=>[
                'reference'=>$shipment->reference,'status'=>$shipment->status,
                'origin_country'=>$shipment->origin_country,
                'destination_country'=>$shipment->destination_country,
                'flight'=>$shipment->flight_number,'awb'=>$shipment->air_waybill,
                'actual_departure'=>$shipment->actual_departure_at?->copy()->timezone($company->timezone)->format('d M Y H:i'),
                'actual_arrival'=>$shipment->actual_arrival_at?->copy()->timezone($company->timezone)->format('d M Y H:i'),
                'notes'=>$shipment->notes,
            ],
            'header'=>$header,
            'lines'=>$lines,'product_summary'=>array_values($productSummary),
            'totals'=>[
                'cartons'=>count($lines),
                'net'=>(string)$net->toScale(3),
                'gross'=>(string)$gross->toScale(3),
                'tare'=>(string)$tare->toScale(3),
            ],
            'issued_at'=>now($company->timezone)->format('d M Y H:i T'),
            'timezone'=>$company->timezone,
        ];
    }

    public function issue(ExportShipment $shipment, ExportDocumentTemplate $template,
        int $userId): ExportDocument
    {
        return DB::transaction(function () use ($shipment,$template,$userId) {
            $shipment=ExportShipment::query()
                ->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless(in_array($shipment->status,['ready','dispatched','delivered'],true),422,
                'Mark the shipment ready before issuing export paperwork.');
            abort_unless($template->business_id===$shipment->business_id && $template->active,404);

            $snapshot=$this->snapshot($shipment,$template);
            $version=(int)ExportDocument::query()->where('business_id',$shipment->business_id)
                ->where('shipment_id',$shipment->id)->where('document_type',$template->type)
                ->max('version')+1;
            $type=$template->type==='packing_list' ? 'PL' : 'DN';
            $reference=$shipment->reference.'-'.$type.'-'.str_pad((string)$version,3,'0',STR_PAD_LEFT);
            $snapshot['document']=['number'=>$reference,'version'=>$version,
                'type'=>$template->type];
            $html=view('documents.print',[
                'document'=>$snapshot,
                'headerLabels'=>self::HEADER_LABELS,
                'columnLabels'=>self::COLUMN_LABELS,
                'typeLabels'=>self::TYPES,
            ])->render();
            $record=ExportDocument::create([
                'business_id'=>$shipment->business_id,
                'shipment_id'=>$shipment->id,
                'template_id'=>$template->id,
                'reference'=>$reference,
                'document_type'=>$template->type,
                'version'=>$version,
                'template_version'=>$template->version,
                'data_snapshot'=>$snapshot,
                'html_snapshot'=>$html,
                'sha256'=>hash('sha256',$html),
                'generated_by'=>$userId,
            ]);
            \App\Models\ShipmentEvent::create([
                'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
                'actor_id'=>$userId,'action'=>'document_issued',
                'details'=>[
                    'reference'=>$reference,'type'=>$template->type,
                    'template_version'=>$template->version,
                ],'created_at'=>now(),
            ]);
            return $record;
        },3);
    }
}
