<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FinancePeriod extends Model{
 protected $fillable=['business_id','period_from','period_to','status','created_by',
 'requested_by','reviewed_by','reason','close_snapshot','close_hash','closed_at'];
 protected function casts():array{return ['period_from'=>'date','period_to'=>'date',
 'close_snapshot'=>'array','closed_at'=>'datetime'];}
 public function events():HasMany{return $this->hasMany(FinancePeriodEvent::class,'period_id');}
}