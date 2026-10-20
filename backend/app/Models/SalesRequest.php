<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class SalesRequest extends Model {
 protected $fillable=['business_id','customer_id','reference','status','required_on','notes','created_by'];
 protected function casts():array{return ['required_on'=>'date'];}
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
 public function items():HasMany{return $this->hasMany(SalesRequestItem::class);}
 public function quotations():HasMany{return $this->hasMany(SalesQuotation::class);}
 public function order():\Illuminate\Database\Eloquent\Relations\HasOne{return $this->hasOne(SalesOrder::class);}
}