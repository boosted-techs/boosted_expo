<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReleaseAcceptanceEvent extends Model
{
    public $timestamps=false;
    protected $fillable=['business_id','run_id','check_id','action',
        'actor_id','reason','evidence_sha256','created_at'];
    protected function casts():array{return ['created_at'=>'datetime'];}
}
