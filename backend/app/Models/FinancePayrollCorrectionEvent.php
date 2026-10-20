<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinancePayrollCorrectionEvent extends Model {
 public $timestamps=false;
 protected $fillable=['business_id','payroll_reversal_id','actor_id','action','reason','snapshot','created_at'];
 protected function casts():array{return ['snapshot'=>'array','created_at'=>'datetime'];}
}