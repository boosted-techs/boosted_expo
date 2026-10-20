<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceShipmentCost extends Model {
 protected $fillable=['business_id','shipment_id','reference','category','description',
 'currency','amount','cash_account_id','paid_on','external_reference','status','created_by','posted_at'];
 protected function casts():array{return ['paid_on'=>'date','posted_at'=>'datetime'];}
 public function shipment():BelongsTo{return $this->belongsTo(ExportShipment::class,'shipment_id');}
 public function cashAccount():BelongsTo{return $this->belongsTo(FinanceAccount::class,'cash_account_id');}
}