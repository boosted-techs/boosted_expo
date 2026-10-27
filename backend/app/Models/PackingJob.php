<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackingJob extends Model
{
    protected $fillable = ['sales_order_line_id',
        'business_id', 'reference', 'product_id', 'status', 'planned_on',
        'target_cartons', 'packing_type', 'notes', 'created_by', 'completed_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'planned_on' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cartons(): HasMany
    {
        return $this->hasMany(Carton::class);
    }

    public function losses(): HasMany
    {
        return $this->hasMany(PackingWasteEntry::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }
}
