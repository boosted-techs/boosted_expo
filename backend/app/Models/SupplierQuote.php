<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierQuote extends Model
{
    protected $fillable = [
        'business_id','supply_request_id','supplier_id','quantity','unit_price',
        'currency','delivery_on','notes','revision','status','submitted_at',
    ];
    protected function casts(): array { return ['submitted_at'=>'datetime','delivery_on'=>'date']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function supplyRequest(): BelongsTo { return $this->belongsTo(SupplyRequest::class); }
    public function revisions(): HasMany { return $this->hasMany(SupplierQuoteRevision::class); }
}