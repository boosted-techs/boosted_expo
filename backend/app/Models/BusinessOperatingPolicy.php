<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class BusinessOperatingPolicy extends Model
{
    protected $fillable=[
        'business_id','dispatch_approval_required','buyer_link_days','updated_by',
    ];
    protected function casts():array{return [
        'dispatch_approval_required'=>'boolean','buyer_link_days'=>'integer',
    ];}
}
