<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankMatchEvent;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancePayment;
use App\Models\FinanceShipmentCost;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceTransactionReversal;
use App\Models\FinancePartialBankReturn;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceBankReconciliation
{
    private function readCsv(UploadedFile $file,string $from,string $to):array
    {
        $stream=fopen($file->getRealPath(),'rb');
        if(!$stream) throw ValidationException::withMessages(['file'=>'Unable to read the statement.']);
        try {
            $header=fgetcsv($stream);
            if(!is_array($header)) throw ValidationException::withMessages(['file'=>'CSV is empty.']);
            $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
            if(array_map(fn($x)=>strtolower(trim((string)$x)),$header)
                !==['date','direction','amount','reference','description']) {
                throw ValidationException::withMessages(['file'=>
                    'CSV must contain exactly: date,direction,amount,reference,description.']);
            }
            $rows=[];$credit=BigDecimal::zero();$debit=BigDecimal::zero();$index=1;
            while(($columns=fgetcsv($stream))!==false) {
                if($columns===[null]) continue;
                $index++;
                if($index>2001) throw ValidationException::withMessages(['file'=>'Only 2000 lines per statement are supported.']);
                if(count($columns)!==5) throw ValidationException::withMessages([
                    'file'=>'Statement row '.$index.' has the wrong number of columns.',
                ]);
                [$date,$direction,$amount,$reference,$description]=array_map('trim',$columns);
                $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
                if(!$parsed || $parsed->format('Y-m-d')!==$date || $date<$from || $date>$to) {
                    throw ValidationException::withMessages([
                        'file'=>'Row '.$index.' needs a valid YYYY-MM-DD date within the period.',
                    ]);
                }
                $direction=strtolower($direction);
                if(!in_array($direction,['credit','debit'],true)
                    || !preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D',$amount)
                    || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages([
                        'file'=>'Row '.$index.' must have credit/debit and a positive two-decimal amount.',
                    ]);
                }
                if(strlen($reference)>150 || strlen($description)>255
                    || $description==='') {
                    throw ValidationException::withMessages([
                        'file'=>'Row '.$index.' needs a description and shorter reference fields.',
                    ]);
                }
                $value=app(FinanceMath::class)->money($amount);
                if($direction==='credit') $credit=$credit->plus($value);
                else $debit=$debit->plus($value);
                $rows[]=[
                    'line_number'=>count($rows)+1,'transaction_on'=>$date,
                    'direction'=>$direction,'amount'=>(string)$value,
                    'bank_reference'=>$reference?:null,'description'=>$description,
                ];
            }
            if($rows===[]) throw ValidationException::withMessages([
                'file'=>'The statement must include at least one transaction.',
            ]);
            return [$rows,$credit,$debit];
        }finally{fclose($stream);}
    }

    public function import(Business $business,array $data,UploadedFile $file,int $actor):FinanceBankStatement
    {
        $account=app(FinanceBankProfiles::class)->statementAccount($business,
            $data['bank_profile_id']??null,strtoupper($data['currency']));
        if($data['period_from']>$data['period_to']) {
            throw ValidationException::withMessages(['period_to'=>'Period end must be on or after the start.']);
        }
        [$rows,$credit,$debit]=$this->readCsv($file,$data['period_from'],$data['period_to']);
        $math=app(FinanceMath::class);
        $opening=$math->money($data['opening_balance']);
        $closing=$math->money($data['closing_balance']);
        if(!$opening->plus($credit)->minus($debit)->isEqualTo($closing)) {
            throw ValidationException::withMessages(['closing_balance'=>
                'Opening balance plus credits minus debits must equal closing balance. Check the file and balance figures.']);
        }
        $sha=hash_file('sha256',$file->getRealPath());
        return DB::transaction(function()use($business,$data,$actor,$account,$file,$sha,
            $opening,$closing,$credit,$debit,$rows) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if(FinanceBankStatement::query()->where('business_id',$business->id)
                ->where('account_id',$account->id)->where('file_sha256',$sha)->exists()) {
                throw ValidationException::withMessages(['file'=>'This bank statement file was already imported.']);
            }
            $statement=FinanceBankStatement::create([
                'business_id'=>$business->id,'account_id'=>$account->id,
                'reference'=>'BANK-'.Str::upper((string)Str::ulid()),
                'currency'=>strtoupper($data['currency']),
                'period_from'=>$data['period_from'],'period_to'=>$data['period_to'],
                'opening_balance'=>(string)$opening,'closing_balance'=>(string)$closing,
                'credits'=>(string)$credit,'debits'=>(string)$debit,
                'file_sha256'=>$sha,'filename'=>mb_substr($file->getClientOriginalName(),0,180),
                'status'=>'open','imported_by'=>$actor,
            ]);
            foreach($rows as $row) FinanceBankStatementLine::create([
                ...$row,'business_id'=>$business->id,'statement_id'=>$statement->id,
            ]);
            return $statement;
        },3);
    }

    private function candidate(FinanceBankStatementLine $line,string $type,int $id)
        :FinancePayment|FinanceShipmentCost|FinancePayrollSettlement|FinanceTransactionReversal|FinancePartialBankReturn
    {
        $statement=$line->statement;
        $class=match($type) {
            'payment'=>FinancePayment::class,'shipment_cost'=>FinanceShipmentCost::class,
            'payroll_settlement'=>FinancePayrollSettlement::class,
            'transaction_reversal'=>FinanceTransactionReversal::class,
            'partial_bank_return'=>FinancePartialBankReturn::class,
            default=>abort(422,'Unsupported reconciliation type.'),
        };
        $record=$class::query()->where('business_id',$line->business_id)
            ->when(in_array($type,['transaction_reversal','partial_bank_return'],true),
                fn($query)=>$query->where('posting_status','posted'),
                fn($query)=>$query->whereIn('status',['posted','reversed']))
            ->lockForUpdate()->findOrFail($id);
        // A source legitimately reversed by a separate posted journal remains
        // historical evidence of the original bank transfer. This allows both
        // the original debit and any actual return/credit to be matched later.
        if($type!=='transaction_reversal' && $record->status==='reversed') {
            abort_unless(FinanceTransactionReversal::query()->where('business_id',$line->business_id)
                ->where('source_type',$type)->where('source_id',$record->id)
                ->where('posting_status','posted')->exists(),422,
                'A reversed bank source must have an immutable approved correction journal.');
        }
        $reserved=FinanceTransactionReversal::query()->where('business_id',$line->business_id)
            ->where('return_bank_line_id',$line->id)->first();
        $partialReserved=FinancePartialBankReturn::query()->where('business_id',$line->business_id)
            ->where('return_bank_line_id',$line->id)->first();
        if($partialReserved && !($record instanceof FinancePartialBankReturn
            && $partialReserved->id===$record->id)) {
            throw ValidationException::withMessages(['source'=>
                'This return line is reserved for its specific partial bank correction.']);
        }
        if($reserved && !($record instanceof FinanceTransactionReversal
            && $reserved->id===$record->id)) {
            throw ValidationException::withMessages(['source'=>
                'This imported bank return line is reserved for its specific approved correction.']);
        }
        if($record instanceof FinancePartialBankReturn
            && $record->return_bank_line_id!==$line->id) {
            throw ValidationException::withMessages(['source'=>
                'A posted partial bank return is bound to its specific imported statement line.']);
        }
        if($record instanceof FinanceTransactionReversal || $record instanceof FinancePartialBankReturn) {
            if($record->return_bank_line_id!==null && $record->return_bank_line_id!==$line->id) {
                throw ValidationException::withMessages(['source'=>
                    'This approved correction is reserved for a different, independently imported bank-return line.']);
            }
            $originalBank=array_values(array_filter(
                ($record instanceof FinanceTransactionReversal
                    ?($record->original_snapshot['lines']??[])
                    :($record->data_snapshot['original_lines']??[])),
                fn($row)=>(int)$row['account_id']===$record->cash_account_id
            ));
            abort_unless(count($originalBank)===1,422,'The original bank movement is not unique.');
            // Bank reversals are the opposite of their original bank movement.
            $direction=\Brick\Math\BigDecimal::of($originalBank[0]['debit'])->isGreaterThan(0)
                ?'debit':'credit';
        } else {
            $direction=$record instanceof FinancePayment
                ?($record->direction==='in'?'credit':'debit'):'debit';
        }
        $math=app(FinanceMath::class);
        if($direction!==$line->direction
            || $record->currency!==$statement->currency
            || !$math->money((string)$record->amount)->isEqualTo(
                $math->money((string)$line->amount)
            ) || $record->cash_account_id!==$statement->account_id) {
            throw ValidationException::withMessages(['source'=>
                'Bank direction, currency, recorded account and exact transaction amount must match.']);
        }
        return $record;
    }

    private function log(FinanceBankMatch $match,int $actor,string $action,array $snapshot):void
    {
        FinanceBankMatchEvent::create([
            'business_id'=>$match->business_id,'match_id'=>$match->id,
            'actor_id'=>$actor,'action'=>$action,'snapshot'=>$snapshot,'created_at'=>now(),
        ]);
    }

    public function propose(FinanceBankStatementLine $line,string $type,int $sourceId,
        string $note,int $actor):FinanceBankMatch
    {
        return DB::transaction(function() use($line,$type,$sourceId,$note,$actor){
            $statement=FinanceBankStatement::query()->where('business_id',$line->business_id)
                ->lockForUpdate()->findOrFail($line->statement_id);
            abort_unless($statement->status==='open',422,'The statement is already closed.');
            $line=FinanceBankStatementLine::query()->where('business_id',$statement->business_id)
                ->where('statement_id',$statement->id)->lockForUpdate()->findOrFail($line->id);
            $this->candidate($line,$type,$sourceId);
            $existing=FinanceBankMatch::query()->where('business_id',$line->business_id)
                ->where('statement_line_id',$line->id)->lockForUpdate()->first();
            if($existing && $existing->status!=='rejected') {
                abort(422,'This statement line is already matched or awaiting review.');
            }
            if(FinanceBankMatch::query()->where('business_id',$line->business_id)
                ->where('source_type',$type)->where('source_id',$sourceId)->exists()) {
                throw ValidationException::withMessages(['source'=>'This payment has already been proposed or reconciled elsewhere.']);
            }
            $attributes=[
                'source_type'=>$type,'source_id'=>$sourceId,'status'=>'pending',
                'proposed_by'=>$actor,'confirmed_by'=>null,'confirmed_at'=>null,
                'note'=>$note,
            ];
            if($existing) $existing->update($attributes);
            else $existing=FinanceBankMatch::create([
                'business_id'=>$line->business_id,'statement_line_id'=>$line->id,...$attributes,
            ]);
            $this->log($existing,$actor,'proposed',[
                'source_type'=>$type,'source_id'=>$sourceId,'amount'=>$line->amount,
                'direction'=>$line->direction,'note'=>$note,
            ]);
            return $existing;
        },3);
    }

    public function review(FinanceBankMatch $match,bool $approve,string $note,int $actor):void
    {
        DB::transaction(function()use($match,$approve,$note,$actor){
            // Acquire locks in the same order as proposing: statement, line, match.
            $initial=FinanceBankMatch::query()->where('business_id',$match->business_id)
                ->findOrFail($match->id);
            $sourceLine=FinanceBankStatementLine::query()->where('business_id',$match->business_id)
                ->findOrFail($initial->statement_line_id);
            $statement=FinanceBankStatement::query()->where('business_id',$match->business_id)
                ->lockForUpdate()->findOrFail($sourceLine->statement_id);
            $line=FinanceBankStatementLine::query()->where('business_id',$match->business_id)
                ->where('statement_id',$statement->id)
                ->lockForUpdate()->findOrFail($initial->statement_line_id);
            $match=FinanceBankMatch::query()->where('business_id',$match->business_id)
                ->lockForUpdate()->findOrFail($match->id);
            abort_unless($match->statement_line_id===$line->id,422,'Statement match has changed.');
            abort_unless($match->status==='pending',422,'This match is no longer pending.');
            abort_unless($match->proposed_by!==$actor,422,
                'A different authorised user must review this bank match.');
            abort_unless($statement->status==='open',422,'Statement is closed.');
            if($approve) $this->candidate($line,$match->source_type,$match->source_id);
            $snapshot=['source_type'=>$match->source_type,'source_id'=>$match->source_id,
                'reason'=>$note,'amount'=>$line->amount,'bank_reference'=>$line->bank_reference];
            $match->update([
                'status'=>$approve?'confirmed':'rejected',
                'confirmed_by'=>$approve?$actor:null,
                'confirmed_at'=>$approve?now():null,
                // Release the unique source reference after a rejected suggestion;
                // the original source is preserved in the event snapshot.
                'source_type'=>$approve?$match->source_type:null,
                'source_id'=>$approve?$match->source_id:null,
                'note'=>$note,
            ]);
            $this->log($match,$actor,$approve?'confirmed':'rejected',$snapshot);
        },3);
    }

    public function complete(FinanceBankStatement $statement,int $actor):void
    {
        DB::transaction(function()use($statement,$actor){
            $statement=FinanceBankStatement::query()->where('business_id',$statement->business_id)
                ->lockForUpdate()->findOrFail($statement->id);
            abort_unless($statement->status==='open',422,'Already closed.');
            $all=$statement->lines()->count();
            $confirmed=FinanceBankMatch::query()->where('business_id',$statement->business_id)
                ->whereIn('statement_line_id',$statement->lines()->pluck('id'))
                ->where('status','confirmed')->count();
            abort_unless($all>0 && $all===$confirmed,422,
                'Each imported bank line must be independently confirmed before closing.');
            abort_unless($statement->imported_by!==$actor,422,
                'A different authorised person must complete reconciliation.');
            $statement->update(['status'=>'reconciled','completed_at'=>now()]);
        });
    }
}
