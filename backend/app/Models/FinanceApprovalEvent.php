<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceApprovalEvent extends Model {
 public $timestamps=false;
 protected $fillable=['business_id','posting_request_id','actor_id','action','reason','created_at'];
 protected function casts():array{return ['created_at'=>'datetime'];}
}