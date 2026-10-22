<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TeamMembershipEvent extends Model
{
    public $timestamps=false;
    protected $fillable=[
        'business_id','membership_id','actor_id','action','previous_role',
        'new_role','previous_active','new_active','reason','created_at',
    ];
}
