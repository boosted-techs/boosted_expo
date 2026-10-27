<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelEvent extends Model
{
    protected $fillable = ['business_id', 'generation_id', 'job_id', 'action', 'reason', 'actor_id'];
}
