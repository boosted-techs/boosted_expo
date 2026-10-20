<?php

namespace App\Support;

use App\Models\BusinessMembership;

class BusinessPermissions
{
    // Explicitly deny permissions not listed here. Configurable role grants come in a later pass.
    private const ROLE_GRANTS = [
        'owner' => ['*'],
        'admin' => ['*'],
        'sales' => ['section.overview', 'section.sales', 'catalogue.view'],
        'buyer' => ['section.overview', 'section.purchasing', 'catalogue.view'],
        'warehouse' => ['section.overview', 'section.operations', 'catalogue.view'],
        'packing' => ['section.overview', 'section.operations', 'catalogue.view'],
        'logistics' => ['section.overview', 'section.exports', 'catalogue.view'],
        'accountant' => ['section.overview', 'section.finance', 'catalogue.view'],
        'hr' => ['section.overview', 'section.staff'],
        'viewer' => ['section.overview', 'catalogue.view'],
    ];

    public const INVITABLE_ROLES = [
        'admin', 'sales', 'buyer', 'warehouse', 'packing', 'logistics',
        'accountant', 'hr', 'viewer',
    ];

    public function allows(BusinessMembership $membership, string $permission): bool
    {
        if (! $membership->active || ! $membership->business?->is_active) {
            return false;
        }

        $grants = self::ROLE_GRANTS[$membership->role] ?? [];

        return in_array('*', $grants, true) || in_array($permission, $grants, true);
    }

    public function canInviteRole(BusinessMembership $membership, string $role): bool
    {
        return $this->allows($membership, 'team.invite')
            && in_array($role, self::INVITABLE_ROLES, true)
            && ($role !== 'admin' || $membership->role === 'owner');
    }
}