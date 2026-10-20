<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceOpeningBalance extends Model{
 protected $fillable=['business_id','reference','effective_on','currency','data_snapshot','sha256',
 'posting_status','created_by','posted_at'];
 protected function casts():array{return ['effective_on'=>'date','data_snapshot'=>'array','posted_at'=>'datetime'];}
}