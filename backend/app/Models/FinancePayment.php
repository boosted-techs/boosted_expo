<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePayment extends Model {
 protected $fillable=['business_id','reference','direction','commercial_invoice_id','supplier_bill_id','cash_account_id',
 'currency','amount','settled_base_amount','payment_on','external_reference','status','notes',
 'idempotency_key','created_by','posted_at'];
 protected function casts():array{return ['payment_on'=>'date','posted_at'=>'datetime'];}
 public function invoice():BelongsTo{return $this->belongsTo(CommercialInvoice::class,'commercial_invoice_id');}
 public function bill():BelongsTo{return $this->belongsTo(FinanceSupplierBill::class,'supplier_bill_id');}
 public function cashAccount():BelongsTo{return $this->belongsTo(FinanceAccount::class,'cash_account_id');}
}