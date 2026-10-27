<?php

namespace App\Services;

use App\Models\PlatformAuditEvent;
use App\Models\User;

class PlatformAccess
{
    public const ROLES = ['superadmin', 'business', 'billing', 'support'];

    public const MODULES = ['core', 'sales', 'procurement', 'packing', 'exports', 'finance', 'people', 'integrations'];

    public function allows(?User $user, string $area): bool
    {
        if (! $user?->is_platform_admin) {
            return false;
        }

        return $user->platform_role === 'superadmin' || match ($area) {
            'overview','directory' => true,
            'business' => $user->platform_role === 'business',
            'billing' => $user->platform_role === 'billing',
            'cases' => in_array($user->platform_role, ['business', 'billing', 'support'], true),
            'support','health' => $user->platform_role === 'support',
            default => false,
        };
    }

    public function check(?User $user, string $area): void
    {
        abort_unless($this->allows($user, $area), 403);
    }

    public function audit(?int $business, ?int $actor, string $action, string $reason, array $before = [], array $after = []): void
    {
        PlatformAuditEvent::create(['business_id' => $business, 'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'before_data' => $before, 'after_data' => $after]);
    }
}
