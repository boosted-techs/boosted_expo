<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceSupplierBill extends Model {
 protected $fillable=['business_id','purchase_order_id','reference','vendor_invoice_reference',
 'vendor_invoice_date','accepted_quantity','total','currency','posting_status','notes','created_by','posted_at'];
 protected function casts():array{return ['vendor_invoice_date'=>'date','posted_at'=>'datetime'];}
 public function purchase():BelongsTo{return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
 public function payments():\Illuminate\Database\Eloquent\Relations\HasMany{return $this->hasMany(FinancePayment::class,'supplier_bill_id');}
}