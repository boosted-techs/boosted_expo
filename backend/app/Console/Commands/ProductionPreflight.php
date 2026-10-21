<?php

namespace App\Console\Commands;

use App\Services\OperationsReadiness;
use Illuminate\Console\Command;

class ProductionPreflight extends Command
{
    protected $signature='expo:preflight
        {--live : Check active database and Redis connectivity without mutating business records}
        {--json : Return structured checks without exposing credentials}';

    protected $description='Check all production release prerequisites without deploying or sending money';

    public function handle(OperationsReadiness $readiness):int
    {
        $report=$readiness->inspect((bool)$this->option('live'));
        if($this->option('json')){
            $this->line(json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        }else{
            foreach($report['checks'] as $item){
                $label=$item['status']==='ok'?'PASS':strtoupper($item['status']);
                $this->line(str_pad($label,8).' '.str_pad($item['code'],28).' '.$item['detail']);
            }
            $this->line('Errors: '.$report['errors'].'; warnings: '.$report['warnings']);
            $this->line($report['ready']?'Configuration preflight passed.'
                :'Production not approved: resolve the failed checks first.');
        }
        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
