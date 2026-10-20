<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePostingRequest extends Model {
 protected $fillable=['business_id','source_type','source_id','status','posting_on','exchange_rate_id','basis_notes',
 'requested_by','approved_by','approved_at','posted_by','posted_at'];
 protected function casts():array{return ['posting_on'=>'date','approved_at'=>'datetime','posted_at'=>'datetime'];}
 public function exchangeRate():BelongsTo{return $this->belongsTo(FinanceExchangeRate::class,'exchange_rate_id');}
 public function events():\Illuminate\Database\Eloquent\Relations\HasMany{return $this->hasMany(FinanceApprovalEvent::class,'posting_request_id');}
}