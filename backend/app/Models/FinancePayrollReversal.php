<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePayrollReversal extends Model {
 protected $fillable=['business_id','payroll_run_id','original_journal_id','reference','currency','reason',
 'original_snapshot','sha256','posting_status','created_by','posted_at'];
 protected function casts():array{return ['original_snapshot'=>'array','posted_at'=>'datetime'];}
 public function run():BelongsTo{return $this->belongsTo(FinancePayrollRun::class,'payroll_run_id');}
 public function originalJournal():BelongsTo{return $this->belongsTo(FinanceJournal::class,'original_journal_id');}
}