<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class SalesQuotation extends Model {
 protected $fillable=['business_id','sales_request_id','reference','revision','status',
  'currency','incoterm','valid_until','discount_percent','tax_percent',
  'subtotal','discount_amount','tax_amount','grand_total','notes','created_by',
  'issued_by','issued_at','html_snapshot','sha256'];
 protected function casts():array{return ['valid_until'=>'date','issued_at'=>'datetime'];}
 public function salesRequest():BelongsTo{return $this->belongsTo(SalesRequest::class);}
 public function lines():HasMany{return $this->hasMany(SalesQuotationLine::class);}
 public function order():\Illuminate\Database\Eloquent\Relations\HasOne{return $this->hasOne(SalesOrder::class);}
}