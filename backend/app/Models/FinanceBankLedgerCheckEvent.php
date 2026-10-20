<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceBankLedgerCheckEvent extends Model {
 public $timestamps=false;
 protected $fillable=['business_id','check_id','actor_id','action','notes','snapshot','created_at'];
 protected function casts():array{return ['snapshot'=>'array','created_at'=>'datetime'];}
}