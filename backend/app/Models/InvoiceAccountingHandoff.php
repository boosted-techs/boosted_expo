<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InvoiceAccountingHandoff extends Model {
 protected $fillable=['business_id','commercial_invoice_id','currency','commercial_amount',
    'posting_status','prepared_at','details'];
 protected function casts():array{return ['prepared_at'=>'datetime','details'=>'array'];}
 public function invoice():BelongsTo{return $this->belongsTo(CommercialInvoice::class,'commercial_invoice_id');}
}