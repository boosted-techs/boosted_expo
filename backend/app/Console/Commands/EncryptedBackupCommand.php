<?php

namespace App\Console\Commands;

use App\Services\EncryptedBackup;
use Illuminate\Console\Command;
use Throwable;

class EncryptedBackupCommand extends Command
{
    protected $signature = 'expo:backup
        {--verify= : Verify a particular encrypted backup file without changing live data}
        {--extract= : Extract a verified backup ONLY into a new, separate recovery directory}
        {--json : Machine-readable status without secrets}';

    protected $description = 'Create, authenticate, or stage an encrypted ERP database and private-file backup';

    public function handle(EncryptedBackup $backups):int
    {
        try {
            $file=$this->option('verify');
            $target=$this->option('extract');
            if ($target && !$file) {
                $this->error('Recovery extraction requires an explicit --verify=/absolute/path.expoenc.');
                return self::FAILURE;
            }
            if ($target) {
                $result=$backups->extractForRecovery($file,$target);
                $status='recovery_staged';
            } elseif ($file) {
                $result=$backups->verify($file);
                $status='authenticated';
            } else {
                $result=$backups->create();
                $status='created_and_verified';
            }
            $details=['status'=>$status,'data'=>$result];
            if ($this->option('json')) {
                $this->line(json_encode($details,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            } else {
                $this->info('Encrypted backup: '.$status);
                if(isset($result['path']))$this->line('Stored at: '.$result['path']);
                if(isset($result['database_driver']))$this->line('Database: '.$result['database_driver']);
                $this->warn('Store encryption keys separately and test restores in isolated staging.');
            }
            return self::SUCCESS;
        } catch(Throwable $e) {
            $this->error('Backup operation could not be completed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
