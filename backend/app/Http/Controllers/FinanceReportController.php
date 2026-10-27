<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Services\FinanceManagementReports;
use App\Services\FinanceStatements;
use App\Support\BusinessPermissions;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceReportController extends Controller
{
    private function check(Request $request): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.reports.view'), 403);
    }

    private function dates(Request $request): array
    {
        $today = now($request->attributes->get('activeBusiness')->timezone)->toDateString();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ]);
        $from = $data['from'] ?? now($request->attributes->get('activeBusiness')->timezone)->startOfYear()->format('Y-m-d');
        $to = $data['to'] ?? $today;
        abort_unless($from <= $to, 422, 'Report start date must be before end date.');

        return [$from, $to];
    }

    public function index(Request $request, FinanceStatements $finance): View
    {
        $this->check($request);
        [$from,$to] = $this->dates($request);
        $business = $request->attributes->get('activeBusiness');
        $result = $finance->report($business, $from, $to);
        $management = app(FinanceManagementReports::class);
        $maturity = $management->maturity($business, $to, $result);
        $reconciliation = $management->reconciliation($business, $to);
        $accounts = FinanceAccount::query()->where('business_id', $business->id)
            ->orderBy('code')->get();

        return view('finance.reports', compact('business', 'result', 'accounts', 'from', 'to', 'maturity', 'reconciliation'));
    }

    private function csv(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows as $row) {
                $safe = array_map(function ($cell): string {
                    $cell = (string) ($cell ?? '');

                    return ! preg_match('/^-?\d+(?:\.\d+)?$/D', $cell) && preg_match('/^[=+@\-\t\r\n]/u', ltrim($cell)) ? "'".$cell : $cell;
                }, $row);
                fputcsv($out, $safe, ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function activity(Request $request, FinanceManagementReports $reports): View|StreamedResponse
    {
        $this->check($request);
        [$from, $to] = $this->dates($request);
        $business = $request->attributes->get('activeBusiness');
        $filters = $request->validate(['currency' => ['nullable', 'string', 'size:3'], 'account_id' => ['nullable', 'integer'], 'category' => ['nullable', 'in:operations,financing,equipment,transfer_exchange,opening,book_adjustment'], 'search' => ['nullable', 'string', 'max:120'], 'export' => ['nullable', 'in:csv']]);
        if (! empty($filters['account_id'])) {
            FinanceAccount::query()->where('business_id', $business->id)->findOrFail($filters['account_id']);
        }
        $result = $reports->activity($business, $from, $to, $filters);
        if ($request->input('export') === 'csv') {
            $rows = (function () use ($result, $business, $from, $to, $filters): \Generator {
                foreach ($result['rows'] as $row) {
                    yield [$business->name, $from, $to, $row['date'], $row['reference'], $row['source'], $row['description'], $row['account'], $row['category'], $row['is_actual_money'] ? 'actual money' : 'expense / book entry', $row['currency'], $row['debit'], $row['credit'], $business->base_currency, $row['base_debit'], $row['base_credit'], $row['expense'], $filters['account_id'] ?? '', $filters['currency'] ?? '', $filters['category'] ?? '', $filters['search'] ?? ''];
                }
            })();

            return $this->csv('business-activity-'.$from.'-'.$to.'.csv', ['Business', 'From', 'Through', 'Date', 'Journal', 'Source', 'Description', 'Account', 'Category', 'Meaning', 'Currency', 'Debit', 'Credit', 'Base currency', 'Base debit', 'Base credit', 'Expense net', 'Filter account', 'Filter currency', 'Filter category', 'Filter search'], $rows);
        }
        $accounts = FinanceAccount::query()->where('business_id', $business->id)->orderBy('code')->get();
        $page = max(1, (int) $request->input('page', 1));
        $entries = new LengthAwarePaginator(array_slice($result['rows'], ($page - 1) * 50, 50), count($result['rows']), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('finance.activity', compact('business', 'from', 'to', 'filters', 'result', 'accounts', 'entries'));
    }

    public function export(Request $request, FinanceStatements $finance): StreamedResponse
    {
        $this->check($request);
        [$from, $to] = $this->dates($request);
        $business = $request->attributes->get('activeBusiness');
        $result = $finance->report($business, $from, $to);
        $maturity = app(FinanceManagementReports::class)->maturity($business, $to, $result);
        $rows = [];
        foreach ($result['profit_lines'] as $line) {
            $rows[] = [$business->name, $from, $to, 'period_result', $line['account']->code, $line['account']->name, $business->base_currency, $line['value']];
        }
        foreach (['asset', 'liability', 'equity'] as $type) {
            foreach ($result['balance_categories'][$type] as $line) {
                $rows[] = [$business->name, $from, $to, $type, $line['account']->code, $line['account']->name, $business->base_currency, $line['value']];
            }
        }
        foreach ($maturity['categories'] as $category => $lines) {
            foreach ($lines as $line) {
                $rows[] = [$business->name, $from, $to, $category, $line['account']->code, $line['account']->name, $business->base_currency, $line['value']];
            }
        }

        $reconciliation = app(FinanceManagementReports::class)->reconciliation($business, $to);
        foreach ($rows as &$row) {
            $row = array_pad($row, 17, '');
        } unset($row);
        foreach ($maturity['tradeAging'] as $line) {
            $rows[] = [$business->name, $from, $to, 'trade_aging', '', $line['reference'], $business->base_currency, $line['base'], $line['currency'], $line['amount'], $line['due_on'], $line['bucket'], $line['kind'], '', '', '', ''];
        }
        foreach ($maturity['rows'] as $line) {
            $rows[] = [$business->name, $from, $to, 'loan_maturity', '', $line['reference'], $business->base_currency, $line['base'], $line['currency'], $line['principal'], $line['matures_on'], '', $line['party'], $line['current_base'], $line['interest'], $line['fees'], ''];
        }
        foreach ($reconciliation['inventory']['controls'] as $line) {
            $rows[] = [$business->name, $from, $to, 'inventory_control', '', $line['label'], $business->base_currency, (string) $line['book'], '', '', '', '', (string) $line['source'], '', '', '', (string) $line['difference']];
        }
        foreach ($reconciliation['inventory']['rows'] as $line) {
            $rows[] = [$business->name, $from, $to, 'batch_valuation', '', $line['reference'], $business->base_currency, (string) $line['cost'], '', (string) $line['quantity'], '', '', $line['product'].' · '.$line['unit'], (string) $line['physical'], '', '', (string) $line['quantity_difference']];
        }
        foreach ($reconciliation['issues'] as $line) {
            $rows[] = [$business->name, $from, $to, 'reconciliation_issue', '', $line['reference'], $business->base_currency, '', '', '', '', $line['kind'], $line['note'], '', '', '', ''];
        }

        return $this->csv('financial-position-'
.$to.'.csv', ['Business', 'From', 'Through', 'Report section', 'Account code', 'Account', 'Base currency', 'Base value', 'Original currency', 'Original amount / valued quantity', 'Due / maturity', 'Age / issue', 'Detail / source value', 'Current principal / observed quantity', 'Interest', 'Fees', 'Difference'], $rows);
    }

    public function ledger(Request $request, int $account, FinanceStatements $finance): View|StreamedResponse
    {
        $this->check($request);
        [$from,$to] = $this->dates($request);
        $business = $request->attributes->get('activeBusiness');
        $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($account);
        $result = $finance->ledger($business, $account, $from, $to);

        if ($request->input('export') === 'csv') {
            $rows = (function () use ($result, $business, $account, $from, $to): \Generator {
                foreach ($result['entries'] as $entry) {
                    yield [$business->name, $from, $to, $account->code, $account->name, $entry['date'], $entry['reference'], $entry['description'], $entry['currency'], $entry['original_debit'], $entry['original_credit'], $business->base_currency, $entry['debit'], $entry['credit'], $entry['balance']];
                }
            })();

            return $this->csv('account-ledger-'.$account->code.'-'.$from.'-'.$to.'.csv', ['Business', 'From', 'Through', 'Account code', 'Account', 'Date', 'Journal', 'Description', 'Original currency', 'Original debit', 'Original credit', 'Base currency', 'Base debit', 'Base credit', 'Running base balance'], $rows);
        }

        return view('finance.account-ledger', compact('business', 'account', 'result', 'from', 'to'));
    }
}
