<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ReleaseIntegrityInspector;
use Illuminate\Console\Command;

class ReleaseDataAuditCommand extends Command
{
    protected $signature='expo:data-audit
        {business : Business numeric ID}
        {--json : Machine-readable checks; no source data or credentials}';

    protected $description='Read-only tenant-scoped stock, finance and document integrity checks';

    public function handle(ReleaseIntegrityInspector $inspector):int
    {
        $business=Business::query()->find((int)$this->argument('business'));
        if(!$business){
            $this->error('Business not found. Nothing was modified.');
            return self::FAILURE;
        }
        $report=$inspector->inspect($business);
        if($this->option('json')){
            $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }else{
            foreach($report['checks'] as $check){
                $this->line(str_pad(strtoupper($check['status']),8).' '.
                    str_pad($check['code'],35).' '.$check['detail']);
            }
            $this->line('Anomalous check groups: '.$report['errors']);
        }
        return $report['ready']?self::SUCCESS:self::FAILURE;
    }
}
