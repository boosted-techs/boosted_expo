<?php

namespace App\Http\Controllers;

use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementAttestation;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
use App\Services\FinanceBankAuthenticity;
use App\Services\FinanceBankLedgerProofs;
use App\Services\FinanceBankReconciliation;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceBankController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    private function businessId(Request $request): int
    {
        return $request->attributes->get('activeBusiness')->id;
    }

    private function own(Request $request, int $id): FinanceBankStatement
    {
        return FinanceBankStatement::query()->where('business_id', $this->businessId($request))
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'finance.bank.view');
        $business = $request->attributes->get('activeBusiness');
        $statements = FinanceBankStatement::query()->where('business_id', $business->id)
            ->withCount('lines')->latest()->paginate(20);
        $bankProfiles = FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('active', true)->orderBy('label')->get();
        $canImport = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.import');

        return view('finance.bank.index', compact('business', 'statements', 'canImport', 'bankProfiles'));
    }

    public function import(Request $request, FinanceBankReconciliation $bank): RedirectResponse
    {
        $this->check($request, 'finance.bank.import');
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(array_keys(app(BusinessCurrencies::class)->catalogue()))],
            'bank_profile_id' => ['nullable', 'regex:/^(?:legacy|[1-9][0-9]*)$/D'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2'],
            'closing_balance' => ['required', 'numeric', 'decimal:0,2'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);
        $statement = $bank->import($request->attributes->get('activeBusiness'),
            $data, $request->file('file'), $request->user()->id);

        return redirect()->route('finance.bank.show', $statement->id)->with('status',
            'Bank CSV imported as unverified evidence. Independently match its lines against posted records.');
    }

    public function show(Request $request, int $statement): View
    {
        $this->check($request, 'finance.bank.view');
        $statement = $this->own($request, $statement)->load('account');
        $request->validate(['candidate_search' => ['nullable', 'string', 'max:120']]);
        $candidateSearch = trim((string) $request->input('candidate_search'));
        $lines = $statement->lines()->with('match')->orderBy('line_number')->paginate(100)->withQueryString();
        $candidateAmounts = $lines->getCollection()->pluck('amount')->unique()->all();
        $canMatch = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.match');
        $canReview = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.review');
        $payments = FinancePayment::query()->where('business_id', $statement->business_id)
            ->where('cash_account_id', $statement->account_id)
            ->whereIn('status', ['posted', 'reversed'])->where('currency', $statement->currency)
            ->whereIn('amount', $candidateAmounts)
            ->when($candidateSearch !== '', fn ($query) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$candidateSearch.'%')->orWhere('external_reference', 'like', '%'.$candidateSearch.'%')))
            ->orderByDesc('id')->paginate(50, ['*'], 'candidate_payments_page')->withQueryString();
        $costs = FinanceShipmentCost::query()->where('business_id', $statement->business_id)
            ->where('cash_account_id', $statement->account_id)
            ->whereIn('status', ['posted', 'reversed'])->where('currency', $statement->currency)
            ->whereIn('amount', $candidateAmounts)
            ->when($candidateSearch !== '', fn ($query) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$candidateSearch.'%')->orWhere('external_reference', 'like', '%'.$candidateSearch.'%')))
            ->orderByDesc('id')->paginate(50, ['*'], 'candidate_costs_page')->withQueryString();
        $payrollSettlements = FinancePayrollSettlement::query()->where('business_id', $statement->business_id)
            ->where('cash_account_id', $statement->account_id)
            ->whereIn('status', ['posted', 'reversed'])->where('currency', $statement->currency)
            ->whereIn('amount', $candidateAmounts)
            ->when($candidateSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$candidateSearch.'%'))
            ->orderByDesc('id')->paginate(50, ['*'], 'candidate_payrollSettlements_page')->withQueryString();
        $reversals = FinanceTransactionReversal::query()
            ->where('business_id', $statement->business_id)
            ->where('cash_account_id', $statement->account_id)
            ->where('posting_status', 'posted')->where('currency', $statement->currency)
            ->whereIn('amount', $candidateAmounts)
            ->when($candidateSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$candidateSearch.'%'))
            ->orderByDesc('id')->paginate(50, ['*'], 'candidate_reversals_page')->withQueryString();
        $partials = FinancePartialBankReturn::query()
            ->where('business_id', $statement->business_id)
            ->where('cash_account_id', $statement->account_id)
            ->where('posting_status', 'posted')->where('currency', $statement->currency)
            ->whereIn('amount', $candidateAmounts)
            ->when($candidateSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$candidateSearch.'%'))
            ->orderByDesc('id')->paginate(50, ['*'], 'candidate_partials_page')->withQueryString();
        $bankAttestation = FinanceBankStatementAttestation::query()
            ->where('business_id', $statement->business_id)
            ->where('statement_id', $statement->id)->first();
        $isBankAuthenticated = $bankAttestation
            && app(FinanceBankAuthenticity::class)->current($statement);
        $canUploadBankSignature = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.auth.verify');
        $ledgerCheck = FinanceBankLedgerCheck::query()->where('business_id', $statement->business_id)
            ->where('statement_id', $statement->id)->first();
        $isLedgerCheckCurrent = $ledgerCheck
            ? app(FinanceBankLedgerProofs::class)->isCurrent($ledgerCheck) : false;
        $ledgerPreview = null;
        if ($statement->status === 'reconciled') {
            try {
                $ledgerPreview = app(FinanceBankLedgerProofs::class)->preview($statement);
            } catch (\Throwable) {
                $ledgerPreview = null;
            }
        }
        $canProofSubmit = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.proof.prepare');
        $canProofReview = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.bank.proof.review');
        $matched = FinanceBankMatch::query()->where('business_id', $statement->business_id)
            ->where('status', 'confirmed')->whereIn('statement_line_id', $statement->lines()->pluck('id'))
            ->count();

        return view('finance.bank.show', compact(
            'statement', 'lines', 'canMatch', 'canReview', 'payments', 'costs', 'payrollSettlements',
            'reversals', 'partials', 'ledgerCheck', 'ledgerPreview', 'isLedgerCheckCurrent',
            'canProofSubmit', 'canProofReview', 'matched',
            'bankAttestation', 'isBankAuthenticated', 'canUploadBankSignature'
        ));
    }

    public function propose(Request $request, int $statement, int $line,
        FinanceBankReconciliation $bank): RedirectResponse
    {
        $this->check($request, 'finance.bank.match');
        $statement = $this->own($request, $statement);
        $data = $request->validate([
            'candidate' => ['required', 'regex:/^(payment|shipment_cost|payroll_settlement|transaction_reversal|partial_bank_return):[1-9][0-9]*$/D'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        [$type,$sourceId] = explode(':', $data['candidate'], 2);
        $line = FinanceBankStatementLine::query()->where('business_id', $statement->business_id)
            ->where('statement_id', $statement->id)->findOrFail($line);
        $bank->propose($line, $type, (int) $sourceId, $data['note'],
            $request->user()->id);

        return back()->with('status', 'Match proposed; an independent reviewer must confirm.');
    }

    public function confirm(Request $request, int $match, FinanceBankReconciliation $bank): RedirectResponse
    {
        $this->check($request, 'finance.bank.review');
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000']]);
        $match = FinanceBankMatch::query()->where('business_id', $this->businessId($request))
            ->findOrFail($match);
        $bank->review($match, true, $data['note'], $request->user()->id);

        return back()->with('status', 'Statement line independently reconciled to its posted entry.');
    }

    public function reject(Request $request, int $match, FinanceBankReconciliation $bank): RedirectResponse
    {
        $this->check($request, 'finance.bank.review');
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000']]);
        $match = FinanceBankMatch::query()->where('business_id', $this->businessId($request))
            ->findOrFail($match);
        $bank->review($match, false, $data['note'], $request->user()->id);

        return back()->with('status', 'Incorrect match rejected. The statement line can be proposed again.');
    }

    public function authenticateEvidence(Request $request, int $statement,
        FinanceBankAuthenticity $auth): RedirectResponse
    {
        $this->check($request, 'finance.bank.auth.verify');
        $data = $request->validate([
            'signed_attestation' => ['required', 'file', 'max:64'],
            'signature_base64' => ['required', 'string', 'min:44', 'max:8192'],
        ]);
        $statement = $this->own($request, $statement);
        $raw = file_get_contents($request->file('signed_attestation')->getRealPath());
        abort_unless(is_string($raw), 422, 'Unable to read signed statement evidence.');
        $auth->verifyAndRecord($statement, $raw, $data['signature_base64'],
            $request->user()->id);

        return back()->with('status',
            'Detached digital signature verified against the independently provisioned trusted bank public key. The imported statement and bank identity commitment match. This does not authenticate any other CSV or initiate a bank transfer.');
    }

    public function proposeLedgerProof(Request $request, int $statement,
        FinanceBankLedgerProofs $proofs): RedirectResponse
    {
        $this->check($request, 'finance.bank.proof.prepare');
        $data = $request->validate(['notes' => ['required', 'string', 'min:20', 'max:2000']]);
        $record = $this->own($request, $statement);
        $proofs->submit($record, $request->user()->id, $data['notes']);

        return back()->with('status',
            'Bank and ledger figures match. Independent verification requested; this is not bank-authenticated evidence.');
    }

    public function reviewLedgerProof(Request $request, int $check, string $decision,
        FinanceBankLedgerProofs $proofs): RedirectResponse
    {
        $this->check($request, 'finance.bank.proof.review');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['notes' => ['required', 'string', 'min:15', 'max:2000']]);
        $check = FinanceBankLedgerCheck::query()->where('business_id', $this->businessId($request))
            ->findOrFail($check);
        $proofs->review($check, $decision === 'approve', $request->user()->id, $data['notes']);

        return back()->with('status',$decision === 'approve'
            ? 'The uploaded statement and posted bank ledger agree as of this verification.'
            : 'Book-balance proof rejected with audit evidence.');
    }

    public function complete(Request $request,int $statement,FinanceBankReconciliation $bank): RedirectResponse
    {
        $this->check($request,'finance.bank.review');
        $bank->complete($this->own($request,$statement),$request->user()->id);

        return back()->with('status','Every imported statement line is confirmed. The statement has been marked reconciled.');
    }
}
