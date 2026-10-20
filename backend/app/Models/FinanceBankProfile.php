<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinanceBankProfile extends Model{
 protected $fillable=['business_id','account_id','label','institution','currency','masked_reference','active','created_by'];
 protected function casts():array{return ['active'=>'boolean'];}
 public function account():BelongsTo{return $this->belongsTo(FinanceAccount::class,'account_id');}
}