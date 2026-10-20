<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FinancePayrollRun extends Model{
 protected $fillable=['business_id','reference','revision','period_from','period_to','currency',
 'gross_total','deduction_total','net_total','posting_status','notes','data_snapshot',
 'sha256','created_by','posted_at'];
 protected function casts():array{return ['period_from'=>'date','period_to'=>'date','revision'=>'integer',
 'data_snapshot'=>'array','posted_at'=>'datetime'];}
 public function lines():HasMany{return $this->hasMany(FinancePayrollLine::class,'payroll_run_id');}
}