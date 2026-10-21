<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancePeriodReopenRequest extends Model {
    protected $fillable=['business_id','period_id','status','reason',
        'materiality_assessment','external_evidence',
        'original_close_snapshot','original_close_hash','original_closed_at',
        'requested_by','reviewed_by','review_notes','reviewed_at'];
    protected function casts():array{return [
        'original_close_snapshot'=>'array',
        'original_closed_at'=>'datetime','reviewed_at'=>'datetime',
    ];}
    public function period():BelongsTo{return $this->belongsTo(FinancePeriod::class,'period_id');}
}