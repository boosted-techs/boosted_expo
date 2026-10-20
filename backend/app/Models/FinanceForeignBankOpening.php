<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceForeignBankOpening extends Model {
 protected $fillable=['business_id','bank_profile_id','account_id','exchange_rate_id','reference',
 'currency','foreign_amount','base_amount','rate_to_base','effective_on','bank_evidence',
 'data_snapshot','sha256','posting_status','created_by','posted_at'];
 protected function casts():array{return ['effective_on'=>'date','data_snapshot'=>'array','posted_at'=>'datetime'];}
 public function bankProfile():BelongsTo{return $this->belongsTo(FinanceBankProfile::class,'bank_profile_id');}
 public function account():BelongsTo{return $this->belongsTo(FinanceAccount::class,'account_id');}
 public function exchangeRate():BelongsTo{return $this->belongsTo(FinanceExchangeRate::class,'exchange_rate_id');}
}