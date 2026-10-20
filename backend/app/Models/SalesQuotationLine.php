<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SalesQuotationLine extends Model {
 protected $fillable=['business_id','sales_quotation_id','product_id','quantity','unit_price','line_total','product_name','product_sku','unit'];
 public function product():BelongsTo{return $this->belongsTo(Product::class);}
}