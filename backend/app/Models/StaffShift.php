<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffShift extends Model
{
    protected $fillable=[
        'business_id','user_id','starts_at','ends_at','location','notes',
        'status','created_by','cancelled_by','cancel_reason',
    ];
    protected function casts():array {
        return ['starts_at'=>'datetime','ends_at'=>'datetime'];
    }
    public function user():BelongsTo {return $this->belongsTo(User::class);}
    public function attendances():HasMany {return $this->hasMany(StaffAttendance::class,'staff_shift_id');}
}
