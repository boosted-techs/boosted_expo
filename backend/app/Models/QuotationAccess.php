<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationAccess extends Model
{
    protected $fillable = ['business_id', 'sales_quotation_id', 'token_hash', 'recipient_email', 'expires_at', 'revoked_at', 'decision', 'buyer_name', 'buyer_reference', 'decided_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
