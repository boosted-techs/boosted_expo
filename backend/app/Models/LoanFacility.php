<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanFacility extends Model
{
    use HasFactory;

    protected $fillable = ['lending_suspended', 'loan_direction', 'business_id', 'reference', 'lender_name', 'lender_kind', 'currency', 'limit_amount', 'revolving', 'starts_on', 'matures_on', 'agreement_reference', 'terms', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['lending_suspended' => 'boolean', 'revolving' => 'boolean', 'starts_on' => 'date', 'matures_on' => 'date'];
    }
}
