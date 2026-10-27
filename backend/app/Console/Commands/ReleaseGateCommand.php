<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ReleaseGate;
use Illuminate\Console\Command;

class ReleaseGateCommand extends Command
{
    protected $signature='expo:release-gate
        {business : Active business numeric ID}
        {--sha= : Exact SHA-256 of the approved immutable release archive}
        {--live : Run real read-only infrastructure connectivity probes}
        {--json : Print structured non-secret findings}';

    protected $description='Fail-closed, read-only release authorisation diagnostics';

    public function handle(ReleaseGate $gate):int
    {
        $business=Business::query()->find((int)$this->argument('business'));
        if(!$business){
            $this->error('Business not found. Nothing was changed.');
            return self::FAILURE;
        }
        $report=$gate->inspect($business,strtolower((string)$this->option('sha')),
            (bool)$this->option('live'));
        if($this->option('json')){
            $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }else{
            foreach($report['checks'] as $check){
                $this->line(str_pad(strtoupper($check['status']),8).' '.
                    str_pad($check['code'],45).' '.$check['detail']);
            }
            $this->line('Failed: '.$report['errors'].'; warnings: '.$report['warnings']);
            $this->line($report['ready']
                ?'All recorded release prerequisites passed; obtain final authorised release decision.'
                :'BLOCKED: release must not be activated. No data was changed.');
        }
        return $report['ready']?self::SUCCESS:self::FAILURE;
    }
}
