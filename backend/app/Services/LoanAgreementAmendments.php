<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanAgreementAmendment;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class LoanAgreementAmendments
{
    public const FIELDS = ['limit_amount', 'revolving', 'matures_on', 'agreement_reference', 'terms', 'lending_suspended'];

    public function terms(LoanFacility $loan, string $on): array
    {
        $terms = $this->current($loan);
        foreach (LoanAgreementAmendment::query()->where('business_id', $loan->business_id)->where('loan_id', $loan->id)->where('status', 'approved')->whereDate('effective_on', '>', $on)->orderByDesc('effective_on')->orderByDesc('id')->get() as $amendment) {
            abort_unless(hash_equals($amendment->snapshot_hash, app(FinanceWorkflows::class)->hash([$amendment->before, $amendment->after])), 422, 'Loan agreement amendment evidence changed.');
            $terms = $amendment->before;
        }

        return $terms;
    }

    private function current(LoanFacility $loan): array
    {
        return ['limit_amount' => (string) app(FinanceMath::class)->money((string) $loan->limit_amount), 'revolving' => (bool) $loan->revolving, 'matures_on' => $loan->matures_on->toDateString(), 'agreement_reference' => $loan->agreement_reference, 'terms' => $loan->terms, 'lending_suspended' => (bool) $loan->lending_suspended];
    }

    public function propose(Business $b, LoanFacility $loan, array $data, int $actor): LoanAgreementAmendment
    {
        return DB::transaction(function () use ($b, $loan, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $loan = LoanFacility::query()->where('business_id', $b->id)->where('status', 'active')->lockForUpdate()->findOrFail($loan->id);
            ksort($data);
            $hash = app(FinanceWorkflows::class)->hash([$loan->id, $data]);
            $old = LoanAgreementAmendment::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->payload_hash === $hash, 422, 'This amendment request has different agreement details.');

                return $old;
            }abort_if(LoanAgreementAmendment::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('status', 'proposed')->exists(), 422, 'Review or reject the pending agreement amendment first.');
            $before = $this->current($loan);
            $after = ['limit_amount' => (string) app(FinanceMath::class)->money($data['limit_amount']), 'revolving' => (bool) ($data['revolving'] ?? false), 'matures_on' => $data['matures_on'], 'agreement_reference' => $data['agreement_reference'], 'terms' => $data['terms'], 'lending_suspended' => ($loan->loan_direction === 'lend' && ($data['resume_lending'] ?? false)) ? false : (bool) $loan->lending_suspended];
            abort_unless($data['effective_on'] >= now($b->timezone)->toDateString() && $after['matures_on'] >= $data['effective_on'], 422, 'Use a current/future agreed amendment and maturity. Historical terms remain unchanged.');

            return LoanAgreementAmendment::create(['business_id' => $b->id, 'loan_id' => $loan->id, 'effective_on' => $data['effective_on'], 'before' => $before, 'after' => $after, 'snapshot_hash' => app(FinanceWorkflows::class)->hash([$before, $after]), 'reason' => $data['reason'], 'status' => 'proposed', 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }

    public function review(Business $b, LoanAgreementAmendment $amend, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $amend, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $amend = LoanAgreementAmendment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($amend->id);
            abort_unless($amend->status === 'proposed' && $amend->created_by !== $actor && hash_equals($amend->snapshot_hash, app(FinanceWorkflows::class)->hash([$amend->before, $amend->after])), 422, 'A separate reviewer must consider the unchanged proposed agreement.');
            $loan = LoanFacility::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($amend->loan_id);
            if ($approve) {
                abort_unless($amend->effective_on->toDateString() === now($b->timezone)->toDateString(), 422, 'Apply an agreed amendment on its effective date, preserving historical terms.');
                abort_unless($this->current($loan) === $amend->before, 422, 'The facility changed after this proposal.');
                abort_if(FinanceRegisterEntry::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('posting_status', 'not_posted')->exists(), 422, 'Finish pending loan financial records before changing the agreement.');
                $state = app(LoanRegister::class)->state($loan);
                abort_unless(! BigDecimal::of($amend->after['limit_amount'])->isLessThan($state['principal']), 422, 'The amended limit cannot forgive or fall below posted principal. Record any debt settlement separately.');
                foreach (LoanInstalment::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('status', 'active')->whereDate('due_on', '>', $amend->after['matures_on'])->get() as $inst) {
                    $parts = app(LoanRegister::class)->instalmentBalances($inst);
                    abort_unless($parts['principal']->plus($parts['interest'])->plus($parts['fees'])->isZero(), 422, 'Cancel/replace unpaid instalments beyond the new maturity before applying the amended term.');
                }$loan->update($amend->after);
            }
            $amend->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
            app(PlatformAccess::class)->audit($b->id, $actor, 'loan.agreement_amended', $note, $amend->before, ['amendment_id' => $amend->id, 'terms' => $amend->after, 'approved' => $approve]);
        }, 3);
    }
}
