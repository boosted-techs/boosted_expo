<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingRequest extends Model
{
    protected $fillable = [
        'business_id', 'reviewed_by', 'business_name', 'contact_name', 'contact_email',
        'contact_phone', 'industry', 'business_size', 'requested_plan', 'billing_cycle',
        'requirements', 'status', 'admin_notes', 'privacy_accepted_at', 'reviewed_at', 'packages_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'privacy_accepted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'packages_sent_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
