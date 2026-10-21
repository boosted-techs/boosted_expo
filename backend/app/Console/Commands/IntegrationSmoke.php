<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class IntegrationSmoke extends Command
{
    protected $signature='expo:integration-smoke
        {--r2 : Perform one create/read/delete private Cloudflare R2 probe}
        {--mail= : Send a single explicitly authorised test email to this recipient}
        {--confirm-live : Explicitly permit outgoing external network operations}';

    protected $description='Opt-in R2 and SMTP smoke tests; never touch financial operations';

    public function handle():int
    {
        $r2=(bool)$this->option('r2');
        $recipient=(string)$this->option('mail');
        if (!$r2 && $recipient==='') {
            $this->warn('No integration selected. Pass --r2 or --mail=recipient and --confirm-live.');
            return self::FAILURE;
        }
        if (!$this->option('confirm-live')) {
            $this->error('External testing is disabled unless you explicitly pass --confirm-live.');
            return self::FAILURE;
        }
        if ($recipient!=='' && !filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide one valid mailbox to test SMTP delivery.');
            return self::FAILURE;
        }
        $failed=false;
        if ($r2) {
            if (!app(\App\Services\ProductImages::class)->configured()) {
                $this->error('R2 credentials and private bucket are not configured.');
                $failed=true;
            } else {
                $key='ops/health/'.Str::uuid().'.txt';
                $content='Boosted Export ERP private R2 integration smoke '.Str::uuid();
                try {
                    $disk=Storage::disk('r2');
                    $wrote=$disk->put($key,$content,['ContentType'=>'text/plain']);
                    if (!$wrote || $disk->get($key)!==$content) {
                        $failed=true;
                        $this->error('R2 write/read verification did not match.');
                    } else {
                        $this->info('R2 private create/read check passed.');
                    }
                } catch(Throwable) {
                    $failed=true;
                    $this->error('R2 provider probe failed; check scoped object permissions and endpoint.');
                } finally {
                    try {
                        Storage::disk('r2')->delete($key);
                    } catch(Throwable) {
                        $failed=true;
                        $this->error('R2 cleanup failed; remove the temporary ops/health object manually.');
                    }
                }
            }
        }
        if ($recipient!=='') {
            if(in_array(config('mail.default'),['log','array'],true)){
                $failed=true;
                $this->error('Mail transport is not a real provider. No message sent.');
            } else {
                try {
                    Mail::raw('This is the explicitly authorised Boosted Export ERP production email transport test. No financial or private customer records are included.',
                        fn($m)=>$m->to($recipient)->subject('Boosted Export ERP: transport smoke test'));
                    $this->info('Test email accepted by configured transport. Confirm inbox receipt independently.');
                } catch(Throwable) {
                    $failed=true;
                    $this->error('The configured outbound email transport failed to accept the test message.');
                }
            }
        }
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
