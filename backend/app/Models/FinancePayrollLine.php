<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePayrollLine extends Model{
 protected $fillable=['business_id','payroll_run_id','user_id','gross','deductions','net'];
 public function user():BelongsTo{return $this->belongsTo(User::class,'user_id');}
}