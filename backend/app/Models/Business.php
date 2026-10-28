<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $fillable = [
        'lifecycle_status', 'security_suspended_at', 'billing_mode', 'billing_review_due_on', 'trial_ends_on', 'billing_exempt_until', 'owner_contact_email',
        'name', 'code', 'country_code', 'base_currency', 'timezone',
        'default_qr_level', 'is_active',
        'enabled_currencies',
        'label_verification_required', 'statement_week_start',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'enabled_currencies' => 'array', 'security_suspended_at' => 'datetime', 'billing_review_due_on' => 'date', 'trial_ends_on' => 'date', 'billing_exempt_until' => 'date', 'label_verification_required' => 'boolean'];
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class)->whereNull('cancelled_at')->where('status', '!=', 'cancelled')->orderByDesc('id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }
}
