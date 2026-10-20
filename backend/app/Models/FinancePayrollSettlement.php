<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePayrollSettlement extends Model {
 protected $fillable=[
 'business_id','payroll_run_id','payroll_line_id','cash_account_id','reference',
 'kind','recipient_name','currency','amount','paid_on','external_reference',
 'evidence_note','status','idempotency_key','created_by','posted_at','data_snapshot','sha256',
 ];
 protected function casts():array{return ['paid_on'=>'date','posted_at'=>'datetime','data_snapshot'=>'array'];}
 public function run():BelongsTo{return $this->belongsTo(FinancePayrollRun::class,'payroll_run_id');}
 public function staffLine():BelongsTo{return $this->belongsTo(FinancePayrollLine::class,'payroll_line_id');}
 public function cashAccount():BelongsTo{return $this->belongsTo(FinanceAccount::class,'cash_account_id');}
}