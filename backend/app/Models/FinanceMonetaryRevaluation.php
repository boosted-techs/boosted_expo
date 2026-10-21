<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceMonetaryRevaluation extends Model {
    protected $fillable=['business_id','reference','source_type','source_id','currency',
        'valued_on','exchange_rate_id','foreign_balance','previous_base','revalued_base',
        'change_base','rate_to_base','valuation_evidence','data_snapshot','sha256',
        'posting_status','created_by','posted_at'];
    protected function casts():array {
        return ['valued_on'=>'date','data_snapshot'=>'array','posted_at'=>'datetime'];
    }
}