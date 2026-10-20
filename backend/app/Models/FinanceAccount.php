<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceAccount extends Model {
 protected $fillable=['business_id','code','name','type','system_key','active'];
 protected function casts():array{return ['active'=>'boolean'];}
}