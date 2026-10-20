<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierRequestLink extends Model
{
    protected $fillable = [
        'business_id','supply_request_id','supplier_id','token_hash','expires_at','sent_at',
    ];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at'=>'datetime','sent_at'=>'datetime'];
    }
    public function supplyRequest(): BelongsTo { return $this->belongsTo(SupplyRequest::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
}