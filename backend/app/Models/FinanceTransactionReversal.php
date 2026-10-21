<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceTransactionReversal extends Model {
 protected $fillable=['business_id','reference','source_type','source_id','original_journal_id',
 'cash_account_id','currency','amount','reason','external_evidence','original_snapshot','sha256',
 'posting_status','created_by','posted_at','original_bank_match_id','return_bank_line_id'];
 protected function casts():array{return ['original_snapshot'=>'array','posted_at'=>'datetime'];}
 public function originalJournal():BelongsTo{return $this->belongsTo(FinanceJournal::class,'original_journal_id');}
 public function cashAccount():BelongsTo{return $this->belongsTo(FinanceAccount::class,'cash_account_id');}
}