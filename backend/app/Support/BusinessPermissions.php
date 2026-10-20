<?php

namespace App\Support;

use App\Models\BusinessMembership;

class BusinessPermissions
{
    // This first release uses conservative predefined grants. A business-managed
    // approval and role-policy editor will be added after operational workflows.
    private const ROLE_GRANTS = [
        'owner' => ['*'],
        'admin' => ['*'],
        'sales' => [
            'section.overview','section.sales','catalogue.view',
            'customers.view','customers.manage','shipments.view','documents.view',
            'sales.view','sales.manage','sales.orders.view','invoices.view',
        ],
        'buyer' => [
            'section.overview','section.purchasing','catalogue.view',
            'suppliers.view','suppliers.manage','procurement.view','stock.view',
            'procurement.create','purchases.view','purchases.create','receipts.view',
        ],
        'warehouse' => [
            'section.overview','section.operations','catalogue.view',
            'purchases.view','receipts.view','receipts.post',
            'stock.view','packing.view','packing.manage','cartons.view',
            'labels.view','labels.generate','traceability.publish',
            'shipments.view','shipments.manage',
            'documents.view','documents.generate',
            'sales.orders.view',
        ],
        'packing' => ['section.overview','section.operations','catalogue.view','receipts.view',
            'stock.view','packing.view','packing.manage','cartons.view','labels.view','labels.generate'],
        'logistics' => ['section.overview','section.exports','catalogue.view','receipts.view',
            'stock.view','packing.view','cartons.view','labels.view','labels.generate',
            'shipments.view','shipments.manage','shipments.dispatch',
            'documents.view','documents.generate','sales.orders.view'],
        'accountant' => [
            'section.overview','section.finance','catalogue.view',
            'customers.view','suppliers.view','procurement.view','purchases.view','receipts.view',
            'stock.view','packing.view','cartons.view','shipments.view','documents.view',
            'sales.view','sales.orders.view','invoices.view','invoices.issue','finance.handoff.view',
            'finance.view','finance.rates.view','finance.rates.propose',
            'finance.documents.view','finance.documents.create',
            'finance.reviews.view','finance.reviews.request','finance.reviews.post',
            'finance.bank.view','finance.bank.import','finance.bank.match',
            'finance.bank.proof.prepare','finance.payment.reversal.prepare',
            'finance.foreign.opening.prepare',
            'finance.inventory.view','finance.inventory.prepare',
            'finance.credits.view','finance.credits.prepare','finance.reports.view',
            'finance.controls.view','finance.opening.prepare','finance.periods.manage',
            'finance.adjustments.prepare','finance.payroll.view','finance.payroll.prepare',
            'finance.payroll.settle','finance.payroll.correct',
        ],
        'hr' => ['section.overview','section.staff','finance.payroll.view','finance.payroll.prepare'],
        'viewer' => ['section.overview','catalogue.view'],
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