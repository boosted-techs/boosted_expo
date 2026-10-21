<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementAttestation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceBankAuthenticity
{
    public function setAccountIdentifier(Business $business,FinanceBankProfile $profile,
        string $rawIdentifier,int $actor):void
    {
        DB::transaction(function()use($business,$profile,$rawIdentifier){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $profile=FinanceBankProfile::query()->where('business_id',$business->id)
                ->where('active',true)->lockForUpdate()->findOrFail($profile->id);
            if(FinanceBankStatementAttestation::query()->where('business_id',$business->id)
                ->where('bank_profile_id',$profile->id)->exists()) {
                throw ValidationException::withMessages(['bank_profile_id'=>
                    'The bank reference cannot be replaced while bank-signed evidence is attached. Contact the platform administrator for an audited account replacement.']);
            }
            $normalised=strtoupper(preg_replace('/[\s-]+/u','',trim($rawIdentifier)));
            if(!preg_match('/^[A-Z0-9\/]{6,80}$/D',$normalised)) {
                throw ValidationException::withMessages(['account_identifier'=>
                    'Enter the actual bank account reference (6–80 letters, numbers or slash characters).']);
            }
            // Only the one-way identifier commitment is stored. No plain bank
            // account reference or account number appears in public responses.
            $publicCommitment=hash('sha256',$normalised);
            $profile->update(['account_identifier_sha256'=>hash_hmac('sha256',
                $publicCommitment,(string)config('app.key'))]);
        },3);
    }

    private function issuer(string $issuerId,string $keyId):array
    {
        $config=config('finance_banks.trusted_issuers',[]);
        $issuer=is_array($config)?($config[$issuerId]??null):null;
        if(!is_array($issuer) || !($issuer['active']??false)
            || !($issuer['verified_out_of_band']??false)
            || ($issuer['key_id']??null)!==$keyId
            || !is_string($issuer['public_key_pem']??null)
            || !is_string($issuer['institution']??null)) {
            throw ValidationException::withMessages(['attestation'=>
                'No independently onboarded and active bank signing key is configured for this issuer and key ID. Manual CSV matching is not bank authentication.']);
        }
        return $issuer;
    }

    private function validatedPayload(
        FinanceBankStatement $statement,string $rawPayload,string $base64Signature,
        bool $requireRecentSignature=true
    ):array
    {
        if(strlen($rawPayload)>65536 || strlen($rawPayload)<100
            || strlen($base64Signature)>8192) {
            throw ValidationException::withMessages(['attestation'=>
                'The signed evidence or detached signature exceeds safe limits.']);
        }
        $data=json_decode($rawPayload,true);
        if(!is_array($data)) throw ValidationException::withMessages([
            'attestation'=>'The signed attestation must be a valid JSON object.',
        ]);
        $issuerId=$data['issuer_id']??null;$keyId=$data['key_id']??null;
        if(!is_string($issuerId)||!preg_match('/^[A-Za-z0-9_.-]{3,100}$/D',$issuerId)
            ||!is_string($keyId)||!preg_match('/^[A-Za-z0-9_.-]{3,100}$/D',$keyId)) {
            throw ValidationException::withMessages(['attestation'=>
                'Evidence must identify the bank signing organisation and registered signing key.']);
        }
        $issuer=$this->issuer($issuerId,$keyId);
        $profile=FinanceBankProfile::query()->where('business_id',$statement->business_id)
            ->where('account_id',$statement->account_id)->where('active',true)->first();
        if(!$profile || !$profile->account_identifier_sha256
            || mb_strtolower(trim($profile->institution))!==
                mb_strtolower(trim($issuer['institution']))) {
            throw ValidationException::withMessages(['attestation'=>
                'This named bank account has no full account-identifier commitment or its institution does not match the trusted signing organisation.']);
        }
        $fields=[
            'version'=>1,'issuer_id'=>$issuerId,'key_id'=>$keyId,
            'account_identifier_sha256'=>is_string($data['account_identifier_sha256']??null)
                && preg_match('/^[a-f0-9]{64}$/D',$data['account_identifier_sha256'])
                && hash_equals($profile->account_identifier_sha256,
                    hash_hmac('sha256',$data['account_identifier_sha256'],
                        (string)config('app.key')))
                    ? $data['account_identifier_sha256'] : null,
            'file_sha256'=>$statement->file_sha256,
            'currency'=>$statement->currency,
            'period_from'=>$statement->period_from->toDateString(),
            'period_to'=>$statement->period_to->toDateString(),
        ];
        foreach($fields as $field=>$expected) {
            if(($data[$field]??null)!==$expected) {
                throw ValidationException::withMessages(['attestation'=>
                    'Signed statement evidence does not match the imported file, bank account, currency, period or approved issuer.']);
            }
        }
        foreach([
            'opening_balance'=>$statement->opening_balance,
            'closing_balance'=>$statement->closing_balance,
            'credits'=>$statement->credits,
            'debits'=>$statement->debits,
        ] as $field=>$expected) {
            if(!isset($data[$field]) || !is_string($data[$field])
                || !preg_match('/^-?[0-9]{1,18}(?:\.[0-9]{1,2})?$/D',$data[$field])
                || !BigDecimal::of($data[$field])->isEqualTo((string)$expected)) {
                throw ValidationException::withMessages(['attestation'=>
                    'Signed opening/closing bank balances or transaction totals do not match the uploaded statement.']);
            }
        }
        $reference=$data['statement_reference']??null;
        if(!is_string($reference) || mb_strlen($reference)<3
            || mb_strlen($reference)>150) {
            throw ValidationException::withMessages(['attestation'=>
                'A bank-issued statement reference is mandatory.']);
        }
        if(!is_string($data['signed_at']??null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$data['signed_at'])) {
            throw ValidationException::withMessages(['attestation'=>
                'The bank-signed timestamp must include an explicit date, time and timezone.']);
        }
        try {$issuedAt=\Carbon\CarbonImmutable::parse($data['signed_at']);}
        catch(\Throwable){throw ValidationException::withMessages(['attestation'=>
            'A valid bank-issued signing timestamp is required.']);}
        if($issuedAt->isFuture()
            || ($requireRecentSignature && $issuedAt->lessThan(now()->subDays(90)))) {
            throw ValidationException::withMessages(['attestation'=>
                'Only a recent signed bank attestation (within 90 days) may be used to authenticate imported evidence.']);
        }
        $signature=base64_decode($base64Signature,true);
        if($signature===false || strlen($signature)<32) throw ValidationException::withMessages([
            'signature'=>'The signature must be valid base64 detached signature bytes.',
        ]);
        $key=openssl_pkey_get_public($issuer['public_key_pem']);
        if(!$key || openssl_verify($rawPayload,$signature,$key,OPENSSL_ALGO_SHA256)!==1) {
            throw ValidationException::withMessages(['signature'=>
                'The bank signature cannot be verified against the independently onboarded issuer public key.']);
        }
        $canonical=openssl_pkey_get_details($key)['key']??null;
        abort_unless(is_string($canonical),422,'Invalid registered bank signing public key.');
        return [
            'profile'=>$profile,'issuer'=>$issuer,'issuer_id'=>$issuerId,
            'key_id'=>$keyId,'reference'=>$reference,'issued_at'=>$issuedAt,
            'public_key_sha256'=>hash('sha256',$canonical),
            'signature_sha256'=>hash('sha256',$signature),
            'payload_sha256'=>hash('sha256',$rawPayload),
        ];
    }

    public function verifyAndRecord(FinanceBankStatement $statement,
        string $rawPayload,string $base64Signature,int $actor):FinanceBankStatementAttestation
    {
        return DB::transaction(function()use($statement,$rawPayload,$base64Signature,$actor){
            Business::query()->whereKey($statement->business_id)->lockForUpdate()->firstOrFail();
            $statement=FinanceBankStatement::query()->where('business_id',$statement->business_id)
                ->lockForUpdate()->findOrFail($statement->id);
            $proof=$this->validatedPayload($statement,$rawPayload,$base64Signature);
            if(FinanceBankStatementAttestation::query()->where('business_id',$statement->business_id)
                ->where('statement_id',$statement->id)->exists()) {
                throw ValidationException::withMessages(['attestation'=>
                    'An attestation is already recorded for this statement. It cannot be replaced silently.']);
            }
            return FinanceBankStatementAttestation::create([
                'business_id'=>$statement->business_id,'statement_id'=>$statement->id,
                'bank_profile_id'=>$proof['profile']->id,
                'issuer_id'=>$proof['issuer_id'],'issuer_key_id'=>$proof['key_id'],
                'statement_reference'=>$proof['reference'],
                'public_key_sha256'=>$proof['public_key_sha256'],
                'payload_sha256'=>$proof['payload_sha256'],
                'signature_sha256'=>$proof['signature_sha256'],
                'signed_payload'=>$rawPayload,'signature_base64'=>$base64Signature,
                'signed_at'=>$proof['issued_at'],'verified_at'=>now(),
                'verified_by'=>$actor,
            ]);
        },3);
    }

    public function current(FinanceBankStatement $statement):bool
    {
        $record=FinanceBankStatementAttestation::query()
            ->where('business_id',$statement->business_id)
            ->where('statement_id',$statement->id)->first();
        if(!$record) return false;
        try {
            $proof=$this->validatedPayload($statement,
                $record->signed_payload,$record->signature_base64,false);
            return $proof['issuer_id']===$record->issuer_id
                && $proof['key_id']===$record->issuer_key_id
                && $proof['reference']===$record->statement_reference
                && $proof['profile']->id===$record->bank_profile_id
                && $proof['public_key_sha256']===$record->public_key_sha256
                && $proof['payload_sha256']===$record->payload_sha256
                && $proof['signature_sha256']===$record->signature_sha256;
        } catch(\Throwable) {
            return false;
        }
    }
}
