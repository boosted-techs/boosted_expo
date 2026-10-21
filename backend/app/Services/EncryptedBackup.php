<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class EncryptedBackup
{
    private const MAGIC = 'EXPOENC1';
    private const CHUNK = 1024 * 1024;

    private function key(): string
    {
        if (!extension_loaded('sodium') || !extension_loaded('zip')) {
            throw new RuntimeException('libsodium and ZIP extensions are required for secure backups.');
        }
        $encoded = (string) config('expo_operations.backup_key');
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('A separate 32-byte EXPO_BACKUP_KEY_BASE64 is required; do not reuse APP_KEY.');
        }
        return $key;
    }

    private function tempDirectory(): string
    {
        $path = sys_get_temp_dir().'/boosted-expo-backup-'.bin2hex(random_bytes(12));
        if (!mkdir($path,0700) || !is_dir($path)) {
            throw new RuntimeException('Could not prepare a private temporary backup directory.');
        }
        return $path;
    }

    private function cleanup(string $path): void
    {
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $file) {
                if ($file->isFile() && !$file->isLink()) @unlink($file->getPathname());
            }
            @rmdir($path);
        }
    }

    private function safeDestination(string $dir): string
    {
        if ($dir === '' || !str_starts_with($dir,'/')) {
            throw new RuntimeException('Backup directory must be an absolute filesystem location.');
        }
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the private backup destination.');
        }
        $root = realpath($dir);
        if (!$root || !is_writable($root) || is_link($dir)) {
            throw new RuntimeException('Backup directory must be writable and may not be a symlink.');
        }
        $repo = realpath(base_path());
        $public = realpath(public_path());
        if ($root===$repo || str_starts_with($root,$repo.'/')
            || ($public && ($root===$public || str_starts_with($root,$public.'/')))) {
            throw new RuntimeException('Backups must be stored outside the web application and source tree.');
        }
        return $root;
    }

    private function databaseDump(string $workspace): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $target = $workspace.'/database.sqlite';
            $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($target));
            if (!is_file($target)) throw new RuntimeException('SQLite snapshot was not created.');
            return [$target,'database.sqlite','sqlite'];
        }
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Only PostgreSQL and SQLite databases are supported for verified backups.');
        }
        $cfg = $connection->getConfig();
        $target = $workspace.'/database.pgcustom';
        $command = [
            'pg_dump','--format=custom','--no-owner','--no-privileges',
            '--file='.$target,
        ];
        $env = [
            'PGHOST'=>(string)($cfg['host']??'127.0.0.1'),
            'PGPORT'=>(string)($cfg['port']??'5432'),
            'PGUSER'=>(string)($cfg['username']??''),
            'PGDATABASE'=>(string)($cfg['database']??''),
            'PGPASSWORD'=>(string)($cfg['password']??''),
            'PGSSLMODE'=>(string)($cfg['sslmode']??'require'),
        ];
        if (!empty($cfg['sslrootcert'])) $env['PGSSLROOTCERT']=$cfg['sslrootcert'];
        $process = new Process($command,null,$env);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful() || !is_file($target) || filesize($target)===0) {
            throw new RuntimeException('PostgreSQL dump did not finish successfully. Verify pg_dump connectivity and permissions.');
        }
        return [$target,'database.pgcustom','pgsql'];
    }

    private function createZip(string $work): array
    {
        [$dbPath,$dbName,$driver]=$this->databaseDump($work);
        $archive=$work.'/snapshot.zip';
        $zip=new ZipArchive();
        if ($zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true) {
            throw new RuntimeException('Could not initialise ZIP backup bundle.');
        }
        $files=[$dbName=>hash_file('sha256',$dbPath)];
        $zip->addFile($dbPath,$dbName);
        $private=storage_path('app/private');
        $count=0;
        if (is_dir($private)) {
            $iterator=new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($private,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $relative=str_replace('\\','/',substr($file->getPathname(),strlen($private)+1));
                if (str_contains($relative,'..') || str_contains($relative,"\0")) continue;
                $name='files/'.$relative;
                $zip->addFile($file->getPathname(),$name);
                $files[$name]=hash_file('sha256',$file->getPathname());
                $count++;
            }
        }
        $manifest=[
            'version'=>1,'created_at'=>now()->toIso8601String(),
            'database_driver'=>$driver,'database_file'=>$dbName,
            'files'=>$files,'private_files'=>$count,
            'separate_r2_backup_required'=>true,
            'app_key_external_escrow_required'=>true,
        ];
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        if (!$zip->close()) throw new RuntimeException('Cannot finalise the ZIP bundle.');
        return [$archive,$manifest];
    }

    private function writeEncrypted(string $input,string $output,string $key): void
    {
        $in=fopen($input,'rb');$out=fopen($output,'xb');
        if (!$in || !$out) throw new RuntimeException('Could not open secure backup streams.');
        @chmod($output,0600);
        try {
            [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            if (fwrite($out,self::MAGIC.$header)!==strlen(self::MAGIC.$header)) {
                throw new RuntimeException('Failed writing authenticated encryption header.');
            }
            $current=fread($in,self::CHUNK);
            if ($current===false || $current==='') throw new RuntimeException('Empty backup archive.');
            while (true) {
                $next=fread($in,self::CHUNK);
                if ($next===false) throw new RuntimeException('Unreadable snapshot bundle.');
                $tag=$next==='' ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$current,'',$tag);
                $record=pack('N',strlen($cipher)).$cipher;
                if (fwrite($out,$record)!==strlen($record)) {
                    throw new RuntimeException('Encrypted backup write interrupted.');
                }
                if ($next==='') break;
                $current=$next;
            }
            fflush($out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function replicateOffsite(string $encrypted):array
    {
        if (config('expo_operations.offsite_enabled') !== true) {
            return ['status'=>'disabled','reason'=>'No offsite account configured'];
        }
        $prefix=(string)config('expo_operations.offsite_prefix','encrypted-backups');
        if (!preg_match('~^[A-Za-z0-9_/-]{2,100}$~D',$prefix)
            || str_contains($prefix,'..')) {
            throw new RuntimeException('Configured offsite backup prefix is unsafe.');
        }
        $object=trim($prefix,'/').'/'.basename($encrypted);
        $disk=Storage::disk('expo_backup_offsite');
        try {
            $input=fopen($encrypted,'rb');
            if(!$input) throw new RuntimeException('Cannot read the encrypted archive for offsite replication.');
            try {
                $uploaded=$disk->put($object,$input,[
                    'visibility'=>'private','ContentType'=>'application/octet-stream',
                ]);
            } finally {
                fclose($input);
            }
            if(!$uploaded) throw new RuntimeException('Offsite backup provider did not accept the encrypted archive.');
            $remote=$disk->readStream($object);
            if(!$remote) throw new RuntimeException('Offsite encrypted archive could not be read back.');
            try {
                $hash=hash_init('sha256');
                hash_update_stream($hash,$remote);
                $remoteHash=hash_final($hash);
            } finally {
                fclose($remote);
            }
            if(!hash_equals(hash_file('sha256',$encrypted),$remoteHash)) {
                throw new RuntimeException('Offsite backup archive read-back checksum mismatch.');
            }
            return [
                'status'=>'verified','object_key'=>$object,
                'sha256'=>$remoteHash,
            ];
        } catch(\Throwable $error) {
            // Preserve the LOCAL encrypted backup for manual recovery/retry.
            // Never report successful disaster recovery when upload or
            // read-back verification fails.
            throw new RuntimeException('Offsite encrypted backup could not be verified; local encrypted copy was retained.',0,$error);
        }
    }

    public function create():array
    {
        $key=$this->key();
        $directory=$this->safeDestination((string)config('expo_operations.backup_directory'));
        $old=umask(0077);
        $work=$this->tempDirectory();
        $target=$directory.'/expo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.expoenc';
        $locallyVerified=false;
        try {
            [$zip,$manifest]=$this->createZip($work);
            $this->writeEncrypted($zip,$target,$key);
            $verified=$this->verify($target);
            if ($verified['database_driver']!==$manifest['database_driver']) {
                throw new RuntimeException('Backup verification did not agree with the source snapshot.');
            }
            $locallyVerified=true;
            $offsite=$this->replicateOffsite($target);
            return [
                'path'=>$target,'bytes'=>filesize($target),
                'created_at'=>$manifest['created_at'],
                'database_driver'=>$manifest['database_driver'],
                'private_files'=>$manifest['private_files'],
                'verified'=>true,'offsite'=>$offsite,
            ];
        } catch (\Throwable $error) {
            if(!$locallyVerified) @unlink($target);
            throw $error;
        } finally {
            $this->cleanup($work);
            umask($old);
        }
    }

    private function decryptToZip(string $encrypted,string $target):void
    {
        $key=$this->key();
        if (!is_file($encrypted) || is_link($encrypted) || !is_readable($encrypted)) {
            throw new RuntimeException('Encrypted backup file is missing or cannot be read.');
        }
        $in=fopen($encrypted,'rb');
        $out=fopen($target,'xb');
        if (!$in || !$out) throw new RuntimeException('Cannot open backup verification files.');
        @chmod($target,0600);
        try {
            $header=fread($in,strlen(self::MAGIC)
                +SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (!is_string($header) || strlen($header)!==
                strlen(self::MAGIC)+SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
                || !str_starts_with($header,self::MAGIC)) {
                throw new RuntimeException('Unknown or truncated authenticated backup format.');
            }
            $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                substr($header,strlen(self::MAGIC)),$key);
            if ($state===false) throw new RuntimeException('Cannot initialise backup decryption.');
            $final=false;
            while (!feof($in)) {
                $lenBytes=fread($in,4);
                if ($lenBytes==='') break;
                if (!is_string($lenBytes) || strlen($lenBytes)!==4) {
                    throw new RuntimeException('Truncated authenticated backup record.');
                }
                $size=unpack('Nsize',$lenBytes)['size'];
                if ($size<18 || $size>self::CHUNK+64) {
                    throw new RuntimeException('Encrypted record exceeds safe chunk limits.');
                }
                $data='';
                while (strlen($data)<$size) {
                    $chunk=fread($in,$size-strlen($data));
                    if ($chunk===false || $chunk==='') {
                        throw new RuntimeException('Truncated encrypted backup content.');
                    }
                    $data.=$chunk;
                }
                $result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$data);
                if ($result===false) throw new RuntimeException('Backup key invalid or encrypted data was altered.');
                [$plaintext,$tag]=$result;
                if (fwrite($out,$plaintext)!==strlen($plaintext)) {
                    throw new RuntimeException('Failed to write verified temporary backup.');
                }
                if ($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $final=true;
                    if (fread($in,1)!=='') throw new RuntimeException('Unexpected data after final encryption record.');
                    break;
                }
            }
            if (!$final) throw new RuntimeException('Backup missing authenticated final record.');
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function inspectZip(string $zipPath):array
    {
        $zip=new ZipArchive();
        if ($zip->open($zipPath)!==true) throw new RuntimeException('Authenticated backup is not a readable ZIP file.');
        try {
            $raw=$zip->getFromName('manifest.json');
            $manifest=$raw===false?null:json_decode($raw,true);
            if (!is_array($manifest) || ($manifest['version']??null)!==1
                || !in_array($manifest['database_driver']??null,['sqlite','pgsql'],true)
                || !is_array($manifest['files']??null)) {
                throw new RuntimeException('Backup manifest is incomplete or unsupported.');
            }
            if (!isset($manifest['files'][$manifest['database_file']])) {
                throw new RuntimeException('Backup database is not in the signed manifest.');
            }
            $seen=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);
                $name=$stat['name']??'';
                if ($name==='manifest.json') continue;
                if (str_starts_with($name,'/')
                    || str_contains($name,'..')
                    || str_contains($name,'\\')
                    || str_contains($name,"\0")
                    || !preg_match('~^(database\.(?:sqlite|pgcustom)|files/[A-Za-z0-9_./-]+)$~D',$name)) {
                    throw new RuntimeException('Backup contains an unsafe or unsupported file path.');
                }
                $stream=$zip->getStream($name);
                if (!$stream || !isset($manifest['files'][$name])) {
                    throw new RuntimeException('Backup manifest or file content is missing.');
                }
                $hash=hash_init('sha256');
                hash_update_stream($hash,$stream);
                fclose($stream);
                if (!hash_equals($manifest['files'][$name],hash_final($hash))) {
                    throw new RuntimeException('Backup content checksum did not match.');
                }
                $seen[$name]=true;
            }
            if (count($seen)!==count($manifest['files'])) {
                throw new RuntimeException('Backup content count differs from manifest.');
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function verify(string $file):array
    {
        $old=umask(0077);
        $work=$this->tempDirectory();
        try {
            $zip=$work.'/restore.zip';
            $this->decryptToZip($file,$zip);
            return $this->inspectZip($zip);
        } finally {
            $this->cleanup($work);
            umask($old);
        }
    }

    public function extractForRecovery(string $file,string $target):array
    {
        $manifest=$this->verify($file);
        $path=rtrim($target,'/');
        if ($path==='' || !str_starts_with($path,'/') || file_exists($path)) {
            throw new RuntimeException('Recovery directory must be a NEW absolute staging directory.');
        }
        $parent=realpath(dirname($path));
        $app=realpath(base_path());
        if (!$parent || $parent===$app || str_starts_with($parent,$app.'/')) {
            throw new RuntimeException('A recovery extract cannot overwrite or enter the application source tree.');
        }
        $old=umask(0077);
        $work=$this->tempDirectory();
        try {
            $zipPath=$work.'/verified.zip';
            $this->decryptToZip($file,$zipPath);
            $second=$this->inspectZip($zipPath);
            if ($second!=$manifest) throw new RuntimeException('Backup changed between verification and recovery.');
            if (!mkdir($path,0700,true)) throw new RuntimeException('Cannot create recovery staging directory.');
            $zip=new ZipArchive();
            if($zip->open($zipPath)!==true) throw new RuntimeException('Cannot open staged backup.');
            try {
                if (!$zip->extractTo($path)) throw new RuntimeException('Recovery staging extraction failed.');
            } finally {
                $zip->close();
            }
            return $manifest;
        } finally {
            $this->cleanup($work);
            umask($old);
        }
    }
}
