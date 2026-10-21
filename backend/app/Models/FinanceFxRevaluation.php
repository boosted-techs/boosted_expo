<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceFxRevaluation extends Model {
    protected $fillable=[
        'business_id','bank_profile_id','account_id','exchange_rate_id','reference',
        'currency','valued_on','foreign_balance','previous_base','revalued_base',
        'change_base','rate_to_base','valuation_evidence','data_snapshot','sha256',
        'posting_status','created_by','posted_at',
    ];
    protected function casts():array{return [
        'valued_on'=>'date','data_snapshot'=>'array','posted_at'=>'datetime',
    ];}
    public function bankProfile():BelongsTo{return $this->belongsTo(FinanceBankProfile::class,'bank_profile_id');}
    public function exchangeRate():BelongsTo{return $this->belongsTo(FinanceExchangeRate::class,'exchange_rate_id');}
}