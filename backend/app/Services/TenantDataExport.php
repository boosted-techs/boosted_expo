<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

class TenantDataExport
{
    public const TABLES = [
        'partner_followups', 'order_events', 'order_tasks', 'order_supplies', 'order_receipt_allocations', 'stock_reservations', 'market_trips', 'transport_partners', 'transport_legs', 'transport_handovers', 'staff_people', 'staff_rotas', 'staff_rota_requirements', 'staff_shifts', 'staff_attendances', 'staff_breaks', 'staff_leaves', 'staff_attendance_corrections',
        'products', 'customers', 'suppliers', 'sales_requests', 'sales_request_items', 'sales_quotations', 'sales_quotation_lines', 'sales_orders', 'sales_order_lines',
        'purchase_orders', 'goods_receipts', 'stock_batches', 'stock_movements', 'packing_jobs', 'cartons', 'carton_contents', 'packing_waste_entries', 'export_shipments', 'shipment_cartons', 'shipment_events',
        'quotation_accesses', 'finance_payroll_runs', 'finance_payroll_lines', 'finance_payroll_settlements', 'finance_payroll_reversals', 'finance_posting_requests', 'finance_approval_events', 'finance_bank_profiles', 'finance_inventory_reliefs', 'finance_balance_adjustments', 'commercial_invoices', 'finance_accounts', 'finance_journals', 'finance_journal_lines', 'finance_supplier_bills', 'finance_payments', 'finance_operations', 'finance_bill_receipts', 'staff_pay_rates', 'finance_payroll_time_claims', 'finance_exchange_rates', 'finance_shipment_costs',
    ];

    public function write(Business $business): void
    {
        $manifest = ['business' => $business->only(['id', 'name', 'code', 'base_currency', 'country_code', 'timezone']), 'generated_at' => now()->toIso8601String(),
            'scope' => 'Structured operational/accounting rows; private file contents, login credentials, public-link tokens, provider credentials and platform billing are excluded. This is a recovery/data-transfer export, not a full infrastructure backup.', 'tables' => self::TABLES];
        echo '{"manifest":'.json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).',"records":{';
        foreach (self::TABLES as $index => $table) {
            if ($index > 0) {
                echo ',';
            }
            echo json_encode($table).':[';
            $first = true;
            foreach (DB::table($table)->where('business_id', $business->id)->orderBy('id')->cursor() as $row) {
                $record = (array) $row;
                foreach (array_keys($record) as $key) {
                    if (preg_match('/token|credential|password|secret|encrypted|public_url/i', $key)) {
                        unset($record[$key]);
                    }
                }
                if (! $first) {
                    echo ',';
                } $first = false;
                echo json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            echo ']';
        }
        echo '}}';
    }
}
