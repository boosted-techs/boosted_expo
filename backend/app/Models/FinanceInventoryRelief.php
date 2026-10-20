<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceInventoryRelief extends Model {
 protected $fillable=['business_id','reference','kind','shipment_id','waste_entry_id','currency',
 'base_amount','data_snapshot','sha256','posting_status','created_by','posted_at'];
 protected function casts():array{return ['data_snapshot'=>'array','posted_at'=>'datetime'];}
 public function shipment():BelongsTo{return $this->belongsTo(ExportShipment::class,'shipment_id');}
 public function wasteEntry():BelongsTo{return $this->belongsTo(PackingWasteEntry::class,'waste_entry_id');}
}