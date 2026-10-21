<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OperationsReadiness
{
    private array $checks=[];

    private function check(string $id,bool $passes,string $detail,bool $required=true):void
    {
        $this->checks[]=[
            'code'=>$id,'status'=>$passes?'ok':($required?'fail':'warning'),
            'detail'=>$detail,
        ];
    }

    private function availableBackup():bool
    {
        $dir=(string)config('expo_operations.backup_directory');
        if(!is_dir($dir))return false;
        $files=glob(rtrim($dir,'/').'/expo-*.expoenc');
        if(!is_array($files) || $files===[])return false;
        $cutoff=time()-max(1,(int)config('expo_operations.backup_max_age_hours',26))*3600;
        foreach($files as $file){
            if(!is_link($file) && is_file($file) && filesize($file)>128
                && filemtime($file)>=$cutoff) return true;
        }
        return false;
    }

    public function inspect(bool $live=false):array
    {
        $this->checks=[];
        $production=config('app.env')==='production';
        $url=(string)config('app.url');
        $urlHost=parse_url($url,PHP_URL_HOST);
        $canonical=parse_url($url,PHP_URL_SCHEME)==='https'
            && is_string($urlHost) && !preg_match('/^(?:localhost|127\.|.*\.(?:test|invalid|example)|example\.com)$/i',$urlHost);

        $this->check('production_environment',$production,
            'Application must explicitly run as production.');
        $this->check('debug_disabled',config('app.debug')===false,
            'Debug traces must be disabled.');
        $this->check('https_canonical_url',$canonical,
            'Use a publicly reachable canonical HTTPS APP_URL.');
        $key=(string)config('app.key');
        $bytes=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):null;
        $this->check('application_key',is_string($bytes)&&strlen($bytes)===32,
            'A unique 32-byte application encryption key must be provisioned.');
        $this->check('postgresql_selected',config('database.default')==='pgsql',
            'Use a dedicated PostgreSQL production database.');
        $this->check('postgresql_tls',
            config('database.connections.pgsql.sslmode')==='verify-full',
            'Enable PostgreSQL TLS with verified server identity (verify-full).');
        $this->check('pgsql_extension',extension_loaded('pdo_pgsql'),
            'PDO PostgreSQL driver must be loaded.');
        $this->check('redis_client',
            (config('database.redis.client')==='phpredis'&&extension_loaded('redis'))
            || (config('database.redis.client')==='predis'&&class_exists(\Predis\Client::class)),
            'PHP Redis client must be installed and configured.');
        $this->check('redis_tls',
            config('database.redis.default.scheme')==='tls' &&
            config('database.redis.cache.scheme')==='tls',
            'Use authenticated TLS Redis for sessions, cache and queues.');
        $this->check('redis_persistence',
            config('cache.default')==='redis'
            && config('queue.default')==='redis'
            && config('session.driver')==='redis',
            'Use Redis for cache, background jobs and sessions.');
        $this->check('cookie_hardening',
            config('session.secure')===true && config('session.http_only')===true
            && config('session.encrypt')===true
            && in_array(config('session.same_site'),['lax','strict'],true),
            'Require encrypted Secure HttpOnly SameSite session cookies.');
        $mailer=config('mail.default');
        $mailerReady=$mailer!=='log' && $mailer!=='array' && $mailer!=='failover'
            && in_array($mailer,['smtp','ses','postmark','mailgun','resend'],true)
            && filter_var(config('mail.from.address'),FILTER_VALIDATE_EMAIL);
        if($mailer==='smtp'){
            $smtp=config('mail.mailers.smtp',[]);
            $mailerReady=$mailerReady && filled($smtp['host']??null)
                && (int)($smtp['port']??0)>0;
        }
        $this->check('transactional_email',$mailerReady,
            'Configure a real outbound email provider, verified FROM identity and secure SMTP/API delivery.');
        $r2=config('filesystems.disks.r2',[]);
        $endpoint=(string)($r2['endpoint']??'');
        $r2Ready=filled($r2['key']??null)&&filled($r2['secret']??null)
            &&filled($r2['bucket']??null)&&str_starts_with($endpoint,'https://')
            && (bool)preg_match('~^https://[a-z0-9-]+\.r2\.cloudflarestorage\.com/?$~iD',$endpoint);
        $this->check('private_r2_storage',$r2Ready,
            'Cloudflare R2 must have private object credentials, bucket and HTTPS endpoint.');
        $b64=base64_decode((string)config('expo_operations.backup_key'),true);
        $backupKey=is_string($b64)
            &&strlen($b64)===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
        $this->check('separate_backup_key',$backupKey,
            'Provide a separately escrowed 32-byte libsodium backup key.');
        $directory=(string)config('expo_operations.backup_directory');
        $repo=realpath(base_path());
        $dir=realpath($directory);
        $external=$dir!==false && $repo!==false
            && !str_starts_with($dir,dirname($repo).'/');
        $this->check('offsite_backup_target',$external && $dir!==false && is_writable($dir),
            'Use an accessible backup directory outside the ERP source tree, protected by filesystem permissions.');
        $this->check('recent_verified_backup',$this->availableBackup(),
            'Create an encrypted backup and verify recovery within the configured maximum age.');
        $offsite=config('filesystems.disks.expo_backup_offsite',[]);
        $enabled=config('expo_operations.offsite_enabled')===true;
        $offsiteEndpoint=(string)($offsite['endpoint']??'');
        $this->check('offsite_encrypted_replication',
            $enabled && filled($offsite['key']??null)
            && filled($offsite['secret']??null)
            && filled($offsite['bucket']??null)
            && str_starts_with($offsiteEndpoint,'https://')
            && filter_var($offsiteEndpoint,FILTER_VALIDATE_URL),
            'Provision a separately credentialed, private offsite backup bucket. Verify upload/read-back of encrypted archives.');
        $this->check('backup_scheduler',config('expo_operations.backup_schedule')===true,
            'Enable the nightly encrypted database/private-files backup schedule.');
        $this->check('health_scheduler',config('expo_operations.monitor_schedule')===true,
            'Enable the recurring private operational health check.');
        $this->check('alerting_configured',filled(config('expo_operations.alert_webhook')),
            'Configure a private incident alert webhook or external monitoring service.',false);
        $proxies=config('expo_operations.trusted_proxies',[]);
        $this->check('proxy_allowlist',is_array($proxies)
            &&!in_array('*',$proxies,true),
            'Do not trust arbitrary X-Forwarded-* from public clients.');
        $this->check('php_sodium_zip',
            extension_loaded('sodium')&&extension_loaded('zip'),
            'Use authenticated encryption and ZIP extensions for recoverable backups.');
        $env=base_path('.env');
        $permissions=file_exists($env)?(fileperms($env)&0007):0;
        $this->check('secrets_file_permissions',$permissions===0,
            '.env must not grant permissions to other Unix users.');
        $free=@disk_free_space(storage_path());
        $this->check('disk_capacity',$free!==false
            &&($free/(1024*1024*1024))>=(float)config('expo_operations.min_free_disk_gb',2),
            'Maintain adequate disk capacity for logs, queues and database backups.');

        if($live){
            try{DB::connection()->select('SELECT 1 AS ready');
                $this->check('database_connectivity',true,'Database accepted a read-only health probe.');
            }catch(Throwable){$this->check('database_connectivity',false,'Cannot reach the active database.');}
            try{
                $ping=Redis::connection()->ping();
                $this->check('redis_connectivity',(bool)$ping,
                    'Redis accepted the health probe.');
            }catch(Throwable){$this->check('redis_connectivity',false,
                'Cannot reach Redis through the configured PHP client.');}
        }
        $fail=count(array_filter($this->checks,fn($v)=>$v['status']==='fail'));
        $warn=count(array_filter($this->checks,fn($v)=>$v['status']==='warning'));
        return [
            'ready'=>$fail===0,'errors'=>$fail,'warnings'=>$warn,
            'checked_at'=>now()->toIso8601String(),
            'checks'=>$this->checks,
        ];
    }
}
