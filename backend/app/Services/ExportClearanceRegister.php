<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExportClearanceRegister
{
    public const TYPES=[
        'phytosanitary'=>'Phytosanitary / plant-health document',
        'certificate_of_origin'=>'Certificate of origin',
        'export_declaration'=>'Export declaration / customs filing',
        'air_waybill'=>'Air waybill / carrier document',
        'other_permit'=>'Other required export permit',
    ];

    public function submit(ExportShipment $shipment,array $data,int $actor):ExportClearanceCheck
    {
        return DB::transaction(function()use($shipment,$data,$actor){
            Business::query()->whereKey($shipment->business_id)->lockForUpdate()->firstOrFail();
            $shipment=ExportShipment::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless($shipment->status!=='cancelled',422,
                'No new clearance claim may be entered for a cancelled shipment.');
            return ExportClearanceCheck::create([
                'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
                'document_kind'=>$data['document_kind'],
                'authority_name'=>$data['authority_name'],
                'external_reference'=>$data['external_reference'],
                'valid_until'=>$data['valid_until']??null,
                'source_evidence'=>$data['source_evidence'],
                'status'=>'pending_review','submitted_by'=>$actor,
            ]);
        },3);
    }

    public function review(ExportClearanceCheck $record,int $actor,
        bool $accept,string $note):ExportClearanceCheck
    {
        return DB::transaction(function()use($record,$actor,$accept,$note){
            Business::query()->whereKey($record->business_id)->lockForUpdate()->firstOrFail();
            $record=ExportClearanceCheck::query()->where('business_id',$record->business_id)
                ->lockForUpdate()->findOrFail($record->id);
            if($record->status!=='pending_review' || $record->submitted_by===$actor){
                throw ValidationException::withMessages(['review'=>
                    'A different authorised owner or administrator must review the still-pending document.']);
            }
            if($accept && $record->valid_until && $record->valid_until->isPast()){
                throw ValidationException::withMessages(['valid_until'=>
                    'An expired document cannot be accepted for internal clearance review.']);
            }
            $record->update([
                'status'=>$accept?'internally_checked':'rejected',
                'reviewed_by'=>$actor,'review_note'=>$note,'reviewed_at'=>now(),
            ]);
            return $record;
        },3);
    }
}
