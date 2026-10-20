<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FinanceBankMatch extends Model {
 protected $fillable=['business_id','statement_line_id','source_type','source_id','status',
 'proposed_by','confirmed_by','confirmed_at','note'];
 protected function casts():array{return ['confirmed_at'=>'datetime'];}
 public function line():BelongsTo{return $this->belongsTo(FinanceBankStatementLine::class,'statement_line_id');}
 public function events():HasMany{return $this->hasMany(FinanceBankMatchEvent::class,'match_id');}
}