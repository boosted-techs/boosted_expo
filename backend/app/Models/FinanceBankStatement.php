<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceBankStatement extends Model {
 protected $fillable=['business_id','account_id','reference','currency','period_from','period_to',
 'opening_balance','closing_balance','credits','debits','file_sha256','filename','status',
 'imported_by','completed_at'];
 protected function casts():array{return [
  'period_from'=>'date','period_to'=>'date','completed_at'=>'datetime',
 ];}
 public function lines():HasMany{return $this->hasMany(FinanceBankStatementLine::class,'statement_id');}
 public function account():BelongsTo{return $this->belongsTo(FinanceAccount::class,'account_id');}
}