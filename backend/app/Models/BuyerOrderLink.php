<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BuyerOrderLink extends Model
{
    protected $fillable=[
        'business_id','sales_order_id','buyer_email','token_hash',
        'expires_at','revoked_at','created_by','revoked_by',
    ];
    protected $hidden=['token_hash'];
    protected function casts():array{return ['expires_at'=>'datetime','revoked_at'=>'datetime'];}
    public function order():BelongsTo{return $this->belongsTo(SalesOrder::class,'sales_order_id');}
}
