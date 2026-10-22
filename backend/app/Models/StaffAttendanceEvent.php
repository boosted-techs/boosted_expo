<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffAttendanceEvent extends Model
{
    public $timestamps=false;
    protected $fillable=['business_id','attendance_id','actor_id','action','detail','created_at'];
}
