<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceBalanceAdjustment extends Model{
 protected $fillable=['business_id','reference','kind','commercial_invoice_id','supplier_bill_id','currency',
 'amount','reason','data_snapshot','sha256','posting_status','created_by','posted_at'];
 protected function casts():array{return ['data_snapshot'=>'array','posted_at'=>'datetime'];}
 public function invoice():BelongsTo{return $this->belongsTo(CommercialInvoice::class,'commercial_invoice_id');}
 public function bill():BelongsTo{return $this->belongsTo(FinanceSupplierBill::class,'supplier_bill_id');}
}