<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class StockQualityEvent extends Model
{
    public $timestamps=false;
    protected $fillable=[
        'business_id','batch_id','actor_id','action','reason','created_at',
    ];
}
