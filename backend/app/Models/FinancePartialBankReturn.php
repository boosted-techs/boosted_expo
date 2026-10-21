<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinancePartialBankReturn extends Model {
    protected $fillable=['business_id','reference','source_type','source_id',
        'original_journal_id','original_bank_match_id','return_bank_line_id',
        'cash_account_id','currency','amount','reason','external_evidence',
        'data_snapshot','sha256','posting_status','created_by','posted_at'];
    protected function casts():array {
        return ['data_snapshot'=>'array','posted_at'=>'datetime'];
    }
}