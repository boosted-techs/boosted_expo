<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceCreditNote extends Model {
 protected $fillable=['business_id','commercial_invoice_id','reference','currency','amount','reason',
 'posting_status','data_snapshot','html_snapshot','sha256','created_by','issued_at'];
 protected function casts():array{return ['data_snapshot'=>'array','issued_at'=>'datetime'];}
 public function invoice():BelongsTo{return $this->belongsTo(CommercialInvoice::class,'commercial_invoice_id');}
}