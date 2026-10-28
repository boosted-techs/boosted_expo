<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

class TenantDataExport
{
    public const TABLES = ['business_destinations', 'supply_request_items', 'finance_bank_statements', 'finance_bank_statement_lines', 'finance_bank_matches', 'finance_bank_ledger_checks', 'finance_bank_ledger_check_events', 'finance_transaction_reversals', 'finance_partial_bank_returns', 'finance_cutovers', 'finance_opening_items', 'finance_opening_item_uses', 'purchase_amendments', 'order_amendments',
        'material_lots', 'material_movements', 'finance_cost_claims', 'finance_cost_layers', 'finance_cost_layer_reliefs', 'funding_commitments', 'funding_recognitions', 'stock_case_evidence', 'stock_exception_cases', 'stock_exception_events', 'stock_batch_origins', 'stock_batch_depletions', 'finance_trade_credits', 'finance_trade_credit_uses', 'loan_agreement_amendments', 'finance_cash_settlements',
        'payroll_statutory_policies', 'staff_outputs', 'staff_pay_adjustments', 'finance_payroll_input_claims', 'partner_finance_terms', 'trade_collection_cases', 'trade_collection_events', 'advance_requests', 'market_accountabilities', 'trade_credit_exceptions', 'finance_workflow_corrections', 'finance_money_corrections', 'finance_workflow_bank_evidence', 'finance_workflows', 'finance_workflow_legs', 'cash_custodies', 'cash_closes', 'recurring_obligations', 'recurring_occurrences',
        'stock_locations', 'stock_location_events', 'stock_adjustments', 'carton_repacks', 'shipment_requirements', 'export_clearance_checks',
        'business_assets', 'asset_activities', 'loan_facilities', 'loan_instalments', 'finance_register_entries',
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
