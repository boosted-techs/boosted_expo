<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalProviderEvent extends Model
{
    protected $fillable=['business_id','connection_id','kind','provider_event_id',
        'event_type','external_reference','currency','amount','finance_payment_id',
        'shipment_id','occurred_at','provider_signed_at','payload_sha256',
        'encrypted_payload','review_status','reviewed_by','review_note','reviewed_at'];
    protected $hidden=['encrypted_payload'];
    protected function casts():array{return [
        'encrypted_payload'=>'encrypted','occurred_at'=>'datetime',
        'provider_signed_at'=>'datetime','reviewed_at'=>'datetime',
    ];}
}
