<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ShipmentDispatchReview extends Model
{
    protected $fillable=[
        'business_id','shipment_id','ready_event_id','snapshot_sha256',
        'status','requested_by','reviewed_by','review_note','reviewed_at',
    ];
    protected function casts():array{return ['reviewed_at'=>'datetime'];}
}
