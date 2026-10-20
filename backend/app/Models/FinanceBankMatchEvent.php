<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceBankMatchEvent extends Model {
 public $timestamps=false;
 protected $fillable=['business_id','match_id','actor_id','action','snapshot','created_at'];
 protected function casts():array{return ['snapshot'=>'array','created_at'=>'datetime'];}
}