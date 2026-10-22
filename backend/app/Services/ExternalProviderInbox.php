<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ExternalProviderConnection;
use App\Models\ExternalProviderEvent;
use App\Models\ExportShipment;
use App\Models\FinancePayment;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalProviderInbox
{
    public const EVENT_TYPES=[
        'payment'=>['payment_settled','payment_failed','payment_reversed'],
        'carrier'=>['carrier_accepted','carrier_departed','carrier_arrived','carrier_exception'],
    ];

    public function accept(string $kind,string $provider,string $raw,
        string $timestamp,string $signature):ExternalProviderEvent
    {
        abort_unless(isset(self::EVENT_TYPES[$kind])
            && preg_match('/^[a-z][a-z0-9_-]{2,59}$/D',$provider),404);

        if(strlen($raw)<40 || strlen($raw)>16384
            || !preg_match('/^[0-9]{10}$/D',$timestamp)
            || abs(time()-(int)$timestamp)>300
            || !preg_match('/^[a-f0-9]{64}$/iD',$signature)){
            abort(401,'Provider callback authentication failed.');
        }
        $payload=json_decode($raw,true);
        if(!is_array($payload)||array_is_list($payload)){
            abort(422,'Provider message must be a JSON object.');
        }
        foreach(['account','event_id','event_type','reference','occurred_at'] as $field){
            if(!is_string($payload[$field]??null) || trim($payload[$field])===''){
                abort(422,'Required provider evidence is incomplete.');
            }
        }
        if(strlen($payload['account'])>120 || strlen($payload['event_id'])>120
            || strlen($payload['reference'])>180
            || !preg_match('/^[A-Za-z0-9_.:@\/-]{3,120}$/D',$payload['event_id'])
            || !in_array($payload['event_type'],self::EVENT_TYPES[$kind],true)){
            abort(422,'Provider event identifier or event type is not supported.');
        }
        if(!is_string($payload['currency']??null) && $kind==='payment'){
            abort(422,'Currency is mandatory for payment evidence.');
        }
        if($kind==='payment' && (!isset($payload['amount'])
            || !is_string($payload['amount'])
            || !preg_match('/^[0-9]{1,18}(?:\.[0-9]{1,2})?$/D',$payload['amount'])
            || BigDecimal::of($payload['amount'])->isLessThanOrEqualTo(0)
            || !preg_match('/^[A-Z]{3}$/D',$payload['currency']))){
            abort(422,'Payment evidence must state a positive source amount and ISO currency.');
        }
        if($kind==='carrier' && isset($payload['location'])
            && (!is_string($payload['location'])||mb_strlen($payload['location'])>160)){
            abort(422,'Carrier location exceeds the accepted evidence limit.');
        }
        if(!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D',
            $payload['occurred_at'])){
            abort(422,'Provider event time must be timezone-aware ISO 8601.');
        }
        try{$occurred=CarbonImmutable::parse($payload['occurred_at'])->utc();}
        catch(\Throwable){abort(422,'Provider event timestamp is invalid.');}
        if($occurred->greaterThan(now()->addMinutes(5))
            || $occurred->lessThan(now()->subYears(2))){
            abort(422,'Provider event time falls outside the accepted evidence window.');
        }
        $connection=ExternalProviderConnection::query()
            ->where('kind',$kind)->where('provider_code',$provider)
            ->where('provider_account',$payload['account'])->where('active',true)
            ->first();
        if(!$connection || !Business::query()->whereKey($connection->business_id)
            ->where('is_active',true)->exists()){
            abort(401,'Provider connection is not provisioned or enabled.');
        }
        $secret=$connection->signing_secret;
        if(!is_string($secret) || strlen($secret)<32 ||
            !hash_equals(hash_hmac('sha256',$timestamp."\n".$raw,$secret),
                strtolower($signature))){
            abort(401,'Provider callback signature is invalid.');
        }
        return DB::transaction(function() use($kind,$connection,$payload,$raw,$timestamp,$occurred){
            Business::query()->whereKey($connection->business_id)
                ->lockForUpdate()->firstOrFail();
            $current=ExternalProviderEvent::query()
                ->where('connection_id',$connection->id)
                ->where('provider_event_id',$payload['event_id'])->first();
            if($current){
                if(!hash_equals($current->payload_sha256,hash('sha256',$raw))){
                    throw ValidationException::withMessages(['event_id'=>
                        'This provider event ID has already been used with different evidence.']);
                }
                return $current;
            }
            $payment=null;$shipment=null;$status='needs_review';
            if($kind==='payment'){
                $rows=FinancePayment::query()->where('business_id',$connection->business_id)
                    ->where('external_reference',$payload['reference'])->limit(2)->get();
                if($rows->count()===1){
                    $candidate=$rows->first();
                    if($candidate->currency===$payload['currency']
                        && BigDecimal::of((string)$candidate->amount)
                            ->isEqualTo($payload['amount'])
                        && $candidate->status==='posted'){
                        $payment=$candidate->id;
                    }
                }
            }else{
                $rows=ExportShipment::query()->where('business_id',$connection->business_id)
                    ->where('air_waybill',$payload['reference'])->limit(2)->get();
                if($rows->count()===1 && $rows->first()->air_waybill){
                    $shipment=$rows->first()->id;
                }
            }
            if(!$payment && !$shipment){
                $status='unmatched';
            }
            return ExternalProviderEvent::create([
                'business_id'=>$connection->business_id,'connection_id'=>$connection->id,
                'kind'=>$kind,'provider_event_id'=>$payload['event_id'],
                'event_type'=>$payload['event_type'],
                'external_reference'=>$payload['reference'],
                'currency'=>$kind==='payment'?$payload['currency']:null,
                'amount'=>$kind==='payment'?$payload['amount']:null,
                'finance_payment_id'=>$payment,'shipment_id'=>$shipment,
                'occurred_at'=>$occurred,
                'provider_signed_at'=>CarbonImmutable::createFromTimestampUTC((int)$timestamp),
                'payload_sha256'=>hash('sha256',$raw),'encrypted_payload'=>$raw,
                'review_status'=>$status,
            ]);
        },3);
    }

    public function review(ExternalProviderEvent $event,int $actor,bool $accept,
        string $note):ExternalProviderEvent
    {
        return DB::transaction(function()use($event,$actor,$accept,$note){
            Business::query()->whereKey($event->business_id)->lockForUpdate()->firstOrFail();
            $event=ExternalProviderEvent::query()->where('business_id',$event->business_id)
                ->lockForUpdate()->findOrFail($event->id);
            if(!in_array($event->review_status,['needs_review','unmatched'],true)){
                throw ValidationException::withMessages(['review'=>
                    'This provider event has already received a final review.']);
            }
            if($accept && !$event->finance_payment_id && !$event->shipment_id){
                throw ValidationException::withMessages(['review'=>
                    'Unmatched external evidence cannot be accepted against a company record.']);
            }
            if($accept && $event->kind==='payment'){
                $payment=FinancePayment::query()->where('business_id',$event->business_id)
                    ->findOrFail($event->finance_payment_id);
                if($payment->status!=='posted' ||
                    $payment->external_reference!==$event->external_reference ||
                    $payment->currency!==$event->currency ||
                    !BigDecimal::of((string)$payment->amount)->isEqualTo((string)$event->amount)){
                    throw ValidationException::withMessages(['review'=>
                        'Original payment data changed; the provider event must not be accepted.']);
                }
            }
            if($accept && $event->kind==='carrier'){
                $shipment=ExportShipment::query()->where('business_id',$event->business_id)
                    ->findOrFail($event->shipment_id);
                if($shipment->air_waybill!==$event->external_reference){
                    throw ValidationException::withMessages(['review'=>
                        'The carrier evidence no longer matches the shipment air waybill.']);
                }
            }
            $event->update([
                'review_status'=>$accept?'reviewed_evidence':'rejected',
                'reviewed_by'=>$actor,'review_note'=>$note,'reviewed_at'=>now(),
            ]);
            // This is strictly audit evidence. It NEVER posts GL journals,
            // allocates payment receipts or modifies shipment status.
            return $event;
        },3);
    }
}
