<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SalesRequestItem extends Model {
 protected $fillable=['business_id','sales_request_id','product_id','quantity'];
 public function product():BelongsTo{return $this->belongsTo(Product::class);}
}