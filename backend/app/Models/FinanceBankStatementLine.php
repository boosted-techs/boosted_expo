<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class FinanceBankStatementLine extends Model {
 protected $fillable=['business_id','statement_id','line_number','transaction_on','direction',
 'amount','bank_reference','description'];
 protected function casts():array{return ['transaction_on'=>'date'];}
 public function statement():BelongsTo{return $this->belongsTo(FinanceBankStatement::class,'statement_id');}
 public function match():HasOne{return $this->hasOne(FinanceBankMatch::class,'statement_line_id');}
}