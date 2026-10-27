<?php
namespace App\Console\Commands;

use App\Services\ReleaseBuildVerifier;
use Illuminate\Console\Command;

class ReleaseBuildFingerprintCommand extends Command
{
    protected $signature='expo:build-fingerprint {--json : Output the checksums in machine-readable form}';
    protected $description='Read-only fingerprint of the validated public Vite production build';

    public function handle(ReleaseBuildVerifier $verifier):int
    {
        $result=$verifier->inspect();
        if($this->option('json')){
            $this->line(json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        }elseif($result['ok']){
            $this->line('Build SHA-256: '.$result['sha256']);
            $this->line('Files verified: '.$result['files']);
            $this->line('The build is fingerprinted, not independently accepted or deployed.');
        }else{
            $this->error($result['issue']??'Cannot verify compiled frontend.');
        }
        return $result['ok']?self::SUCCESS:self::FAILURE;
    }
}
