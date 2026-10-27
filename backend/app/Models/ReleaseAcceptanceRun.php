<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReleaseAcceptanceRun extends Model
{
    protected $fillable=['business_id','artifact_sha256','assets_sha256','release_label',
        'status','scope_note','created_by','sealed_by','sealed_at'];
    protected function casts():array{return ['sealed_at'=>'datetime'];}
    public function checks():HasMany{return $this->hasMany(ReleaseAcceptanceCheck::class,'run_id');}
    public function events():HasMany{return $this->hasMany(ReleaseAcceptanceEvent::class,'run_id');}
}
