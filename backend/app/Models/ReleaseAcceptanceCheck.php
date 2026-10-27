<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReleaseAcceptanceCheck extends Model
{
    protected $fillable=['business_id','run_id','check_code','status',
        'evidence_reference','test_notes','tested_by','tested_at',
        'reviewed_by','review_notes','reviewed_at'];
    protected function casts():array{return ['tested_at'=>'datetime','reviewed_at'=>'datetime'];}
}
