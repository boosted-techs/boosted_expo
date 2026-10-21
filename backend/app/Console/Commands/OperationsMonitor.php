<?php

namespace App\Console\Commands;

use App\Services\OperationsReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OperationsMonitor extends Command
{
    protected $signature='expo:monitor
        {--json : Print machine-readable operational status}
        {--no-notify : Never send a webhook, even when configured}';

    protected $description='Check private ERP production infrastructure and report actionable incidents';

    public function handle(OperationsReadiness $readiness):int
    {
        $report=$readiness->inspect(true);
        $issues=array_values(array_map(fn($v)=>$v['code'],
            array_filter($report['checks'],fn($c)=>$c['status']==='fail')));
        try {
            if (Schema::hasTable('failed_jobs')) {
                $failed=DB::table('failed_jobs')
                    ->where('failed_at','>=',now()->subHour())->count();
                if($failed>0)$issues[]='failed_jobs_last_hour';
            }
        } catch (Throwable) {$issues[]='failed_jobs_unavailable';}

        // Keep monitoring payloads free of personal, customer and financial data.
        $payload=[
            'project'=>'Boosted Export ERP',
            'timestamp'=>now()->toIso8601String(),
            'healthy'=>count($issues)===0,'incidents'=>array_values(array_unique($issues)),
        ];
        if($this->option('json'))$this->line(json_encode($payload,JSON_THROW_ON_ERROR));
        else $this->line($payload['healthy']?'ERP operational checks passed.'
            :'ERP operational checks require attention: '.implode(', ',$payload['incidents']));

        if(!$payload['healthy']){
            Log::critical('ERP operational check failed',
                ['codes'=>$payload['incidents'],'timestamp'=>$payload['timestamp']]);
            $webhook=(string)config('expo_operations.alert_webhook');
            if (!$this->option('no-notify') && str_starts_with($webhook,'https://')){
                try {
                    Http::timeout(5)->retry(1,200)->post($webhook,$payload)->throw();
                } catch(Throwable) {
                    Log::critical('ERP incident webhook could not be delivered');
                }
            }
        }
        return $payload['healthy']?self::SUCCESS:self::FAILURE;
    }
}
