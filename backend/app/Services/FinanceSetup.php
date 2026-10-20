<?php
namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;

class FinanceSetup
{
    public const ACCOUNTS=[
        ['1010','Cash on Hand','asset','cash'],
        ['1020','Bank & Electronic Receipts','asset','bank'],
        ['1200','Trade Receivables','asset','receivable'],
        ['1300','Produce Inventory','asset','inventory'],
        ['2100','Trade Payables','liability','payable'],
        ['2300','Net Payroll Payable','liability','payroll_payable'],
        ['2400','Payroll Withholding Payable','liability','payroll_withholding'],
        ['2200','Sales Tax Payable','liability','sales_tax'],
        ['3100','Opening Capital & Historical Equity','equity','opening_equity'],
        ['4100','Export Sales Revenue','income','export_sales'],
        ['5100','Cost of Goods Sold','expense','cogs'],
        ['5200','Inventory Sorting & Spoilage Loss','expense','waste_loss'],
        ['6200','Shipment Operating Costs','expense','shipment_cost'],
        ['6300','Staff Payroll Expense','expense','payroll_expense'],
        ['7110','Realised Foreign Exchange Gain','income','fx_gain'],
        ['7120','Realised Foreign Exchange Loss','expense','fx_loss'],
    ];

    public function activate(Business $business):int
    {
        return DB::transaction(function() use($business) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $created=0;
            foreach(self::ACCOUNTS as [$code,$name,$type,$key]) {
                $exists=FinanceAccount::query()->where('business_id',$business->id)
                    ->where('system_key',$key)->exists();
                if($exists) continue;
                FinanceAccount::create([
                    'business_id'=>$business->id,'code'=>$code,
                    'name'=>$name,'type'=>$type,'system_key'=>$key,'active'=>true,
                ]);
                $created++;
            }
            return $created;
        },3);
    }

    public function account(int $businessId,string $key):FinanceAccount
    {
        $account=FinanceAccount::query()->where('business_id',$businessId)
            ->where('system_key',$key)->where('active',true)->first();
        if(!$account) abort(422,'Set up the company chart of accounts before using this financial action.');
        return $account;
    }
}