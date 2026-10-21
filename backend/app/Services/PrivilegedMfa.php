<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivilegedMfa
{
    public function required(User $user):bool
    {
        if (config('app.env')!=='production') return false;
        if ($user->is_platform_admin) return true;

        return $user->memberships()->where('active',true)
            ->whereIn('role',['owner','admin','accountant','logistics'])
            ->whereHas('business',fn($q)=>$q->where('is_active',true))->exists();
    }

    public function newSecret():string
    {
        $bytes=random_bytes(20);
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer=0;$bits=0;$text='';
        foreach(str_split($bytes) as $byte){
            $buffer=($buffer<<8)|ord($byte);
            $bits+=8;
            while($bits>=5){
                $bits-=5;
                $text.=$alphabet[($buffer>>$bits)&31];
            }
        }
        return $text;
    }

    private function decode(string $secret):string
    {
        if (!preg_match('/^[A-Z2-7]{32}$/D',$secret)) {
            throw new \RuntimeException('Invalid authenticator secret format.');
        }
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer=0;$bits=0;$raw='';
        for($i=0;$i<strlen($secret);$i++){
            $buffer=($buffer<<5)|strpos($alphabet,$secret[$i]);
            $bits+=5;
            if($bits>=8){
                $bits-=8;
                $raw.=chr(($buffer>>$bits)&255);
            }
        }
        return $raw;
    }

    public function oneTimeCode(string $secret,int $step):string
    {
        $key=$this->decode($secret);
        $counter=pack('N2',intdiv($step,4294967296),$step%4294967296);
        $hash=hash_hmac('sha1',$counter,$key,true);
        $offset=ord($hash[19])&15;
        $binary=((ord($hash[$offset])&127)<<24)
            |((ord($hash[$offset+1])&255)<<16)
            |((ord($hash[$offset+2])&255)<<8)
            |(ord($hash[$offset+3])&255);
        return str_pad((string)($binary%1000000),6,'0',STR_PAD_LEFT);
    }

    private function validCounter(string $secret,string $code,int $last):?int
    {
        if(!preg_match('/^[0-9]{6}$/D',$code))return null;
        $now=intdiv(time(),30);
        for($step=$now-1;$step<=$now+1;$step++){
            if($step>$last && hash_equals($this->oneTimeCode($secret,$step),$code))
                return $step;
        }
        return null;
    }

    public function enrol(User $user,string $secret,string $code):array
    {
        return DB::transaction(function()use($user,$secret,$code){
            $locked=User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->mfa_enabled_at,422,
                'An authenticator is already registered. Sign in with your existing code.');
            $step=$this->validCounter($secret,$code,-1);
            if($step===null)throw ValidationException::withMessages([
                'code'=>'The six-digit authenticator code is incorrect or expired.',
            ]);
            $codes=array_map(fn()=>strtoupper(bin2hex(random_bytes(6))),range(1,10));
            $locked->forceFill([
                'mfa_secret'=>$secret,'mfa_recovery_codes'=>array_map(
                    fn($code)=>hash('sha256',$code),$codes),
                'mfa_last_counter'=>$step,'mfa_enabled_at'=>now(),
            ])->save();
            Log::notice('ERP privileged MFA enrolled',['user_id'=>$locked->id]);
            return $codes;
        },3);
    }

    public function verify(User $user,string $code,?string $recovery):bool
    {
        return DB::transaction(function()use($user,$code,$recovery){
            $locked=User::query()->lockForUpdate()->findOrFail($user->id);
            if(!$locked->mfa_enabled_at || !$locked->mfa_secret)return false;
            if($recovery!==null && $recovery!==''){
                $codes=$locked->mfa_recovery_codes??[];
                $normalised=strtoupper(preg_replace('/[\s-]+/','',$recovery));
                $hash=hash('sha256',$normalised);
                foreach($codes as $i=>$candidate){
                    if(hash_equals($candidate,$hash)){
                        unset($codes[$i]);
                        $locked->forceFill(['mfa_recovery_codes'=>array_values($codes)])->save();
                        Log::warning('ERP privileged MFA recovery code used',
                            ['user_id'=>$locked->id,'codes_remaining'=>count($codes)]);
                        return true;
                    }
                }
                return false;
            }
            $step=$this->validCounter($locked->mfa_secret,$code,
                (int)($locked->mfa_last_counter??-1));
            if($step===null)return false;
            $locked->forceFill(['mfa_last_counter'=>$step])->save();
            return true;
        },3);
    }

    public function provisioningUri(User $user,string $secret):string
    {
        $label=rawurlencode('Boosted Export ERP:'.$user->email);
        $issuer=rawurlencode('Boosted Export ERP');
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }
}
