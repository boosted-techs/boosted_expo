<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\ExternalProviderConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProvisionExternalProvider extends Command
{
    protected $signature='expo:provider-provision
        {business : Existing company ID}
        {kind : payment or carrier}
        {provider : Provider identifier from approved onboarding}
        {account : Verified provider-side merchant/airline account identifier}
        {--trust-reference= : Independent legal/onboarding approval ticket}
        {--confirm-verified-onboarding : Confirm operator checked the official provider contract and key}
        {--disable : Disable an existing provider connection without removing its history}';

    protected $description='Provision a provider webhook signing secret from a protected environment variable';

    public function handle():int
    {
        $kind=(string)$this->argument('kind');
        $provider=(string)$this->argument('provider');
        $account=(string)$this->argument('account');
        if(!in_array($kind,['payment','carrier'],true)
            || !preg_match('/^[a-z][a-z0-9_-]{2,59}$/D',$provider)
            || !preg_match('/^[A-Za-z0-9_.:@\/-]{3,120}$/D',$account)){
            $this->error('Unsupported type, provider identifier or external account.');
            return self::FAILURE;
        }
        $business=Business::query()->find((int)$this->argument('business'));
        if(!$business){
            $this->error('The business does not exist.');return self::FAILURE;
        }
        if($this->option('disable')){
            $count=ExternalProviderConnection::query()->where('business_id',$business->id)
                ->where('kind',$kind)->where('provider_code',$provider)
                ->where('provider_account',$account)->update(['active'=>false]);
            $this->info($count?'Connection disabled, existing evidence retained.':'No matching connection found.');
            return $count?self::SUCCESS:self::FAILURE;
        }
        $ticket=(string)$this->option('trust-reference');
        if(!$this->option('confirm-verified-onboarding')
            || strlen($ticket)<12 || strlen($ticket)>200){
            $this->error('Independent provider onboarding confirmation and a trust ticket are mandatory.');
            return self::FAILURE;
        }
        $secret=(string)getenv('EXPO_PROVIDER_SIGNING_SECRET');
        if(strlen($secret)<32 || strlen($secret)>512){
            $this->error('Provide the actual provider webhook signing secret through a private EXPO_PROVIDER_SIGNING_SECRET runtime environment variable.');
            return self::FAILURE;
        }
        try {
            DB::transaction(function()use($business,$kind,$provider,$account,$ticket,$secret){
                Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
                $existing=ExternalProviderConnection::query()
                    ->where('kind',$kind)->where('provider_code',$provider)
                    ->where('provider_account',$account)->lockForUpdate()->first();
                if($existing && $existing->business_id!==$business->id){
                    throw new \RuntimeException(
                        'This external account belongs to another company; it cannot be silently reassigned.');
                }
                ExternalProviderConnection::query()->updateOrCreate(
                    ['business_id'=>$business->id,'kind'=>$kind,
                        'provider_code'=>$provider,'provider_account'=>$account],
                    ['signing_secret'=>$secret,
                        'trust_reference'=>$ticket,'active'=>true],
                );
            },3);
        }catch(\Throwable){
            $this->error('Connection cannot be provisioned; an account may already belong to a different business.');
            return self::FAILURE;
        }
        $this->info('Provider callback key provisioned privately; no account or payment was queried.');
        return self::SUCCESS;
    }
}
