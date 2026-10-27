<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;

class FinanceSetup
{
    public const ACCOUNTS = [
        ['1010', 'Cash on Hand', 'asset', 'cash'],
        ['1020', 'Bank & Electronic Receipts', 'asset', 'bank'],
        ['1200', 'Trade Receivables', 'asset', 'receivable'],
        ['1250', 'Supplier Advances', 'asset', 'supplier_advances'],
        ['1260', 'Staff and Market Advances', 'asset', 'staff_advances'],
        ['2150', 'Customer Deposits', 'liability', 'customer_deposits'],
        ['2160', 'Expenses and Staff Claims Payable', 'liability', 'expense_payable'],
        ['6400', 'Business Operating Expenses', 'expense', 'general_expense'],
        ['1500', 'Equipment at Cost', 'asset', 'asset_cost'],
        ['1590', 'Accumulated Equipment Depreciation', 'asset', 'asset_accumulated'],
        ['2170', 'Equipment Purchases Payable', 'liability', 'asset_payable'],
        ['2500', 'Borrowing Principal', 'liability', 'loan_principal'],
        ['2510', 'Accrued Borrowing Interest', 'liability', 'loan_interest_payable'],
        ['2520', 'Loans from Business Owners', 'liability', 'owner_borrowing'],
        ['6500', 'Equipment Depreciation', 'expense', 'asset_depreciation'],
        ['6510', 'Equipment Disposal and Write-off Loss', 'expense', 'asset_disposal_loss'],
        ['4150', 'Equipment Disposal Gain', 'income', 'asset_disposal_gain'],
        ['6600', 'Borrowing Interest Expense', 'expense', 'loan_interest_expense'],
        ['6610', 'Borrowing Fees Expense', 'expense', 'loan_fee_expense'],
        ['1300', 'Produce Inventory', 'asset', 'inventory'],
        ['2100', 'Trade Payables', 'liability', 'payable'],
        ['2300', 'Net Payroll Payable', 'liability', 'payroll_payable'],
        ['2400', 'Payroll Withholding Payable', 'liability', 'payroll_withholding'],
        ['2200', 'Sales Tax Payable', 'liability', 'sales_tax'],
        ['3100', 'Opening Capital & Historical Equity', 'equity', 'opening_equity'],
        ['4100', 'Export Sales Revenue', 'income', 'export_sales'],
        ['5100', 'Cost of Goods Sold', 'expense', 'cogs'],
        ['5210', 'Stock Count Shortage', 'expense', 'stock_count_loss'],
        ['4160', 'Stock Count Surplus', 'income', 'stock_count_gain'],
        ['5200', 'Inventory Sorting & Spoilage Loss', 'expense', 'waste_loss'],
        ['6200', 'Shipment Operating Costs', 'expense', 'shipment_cost'],
        ['6300', 'Staff Payroll Expense', 'expense', 'payroll_expense'],
        ['7110', 'Realised Foreign Exchange Gain', 'income', 'fx_gain'],
        ['7120', 'Realised Foreign Exchange Loss', 'expense', 'fx_loss'],
        ['7130', 'Unrealised Foreign Exchange Gain', 'income', 'unrealized_fx_gain'],
        ['7140', 'Unrealised Foreign Exchange Loss', 'expense', 'unrealized_fx_loss'],
    ];

    public function activate(Business $business): int
    {
        return DB::transaction(function () use ($business) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $created = 0;
            foreach (self::ACCOUNTS as [$code,$name,$type,$key]) {
                $exists = FinanceAccount::query()->where('business_id', $business->id)
                    ->where('system_key', $key)->exists();
                if ($exists) {
                    continue;
                }
                FinanceAccount::create([
                    'business_id' => $business->id, 'code' => $code,
                    'name' => $name, 'type' => $type, 'system_key' => $key, 'active' => true,
                ]);
                $created++;
            }

            return $created;
        }, 3);
    }

    public function account(int $businessId, string $key): FinanceAccount
    {
        $account = FinanceAccount::query()->where('business_id', $businessId)
            ->where('system_key', $key)->where('active', true)->first();
        if (! $account) {
            abort(422, 'Set up the company chart of accounts before using this financial action.');
        }

        return $account;
    }
}
