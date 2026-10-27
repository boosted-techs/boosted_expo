<?php

namespace App\Services;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

class ReleaseArtifactVerifier
{
    // Code-only attestation. Production dependencies and generated assets still
    // need separate build/lockfile verification and staging acceptance.
    private const SOURCE_PREFIXES = [
        'backend/app/',
        'backend/bootstrap/',
        'backend/config/',
        'backend/database/migrations/',
        'backend/routes/',
        'backend/resources/views/',
        'backend/resources/js/',
        'backend/resources/css/',
        'backend/public/assets/',
        'deploy/scripts/',
    ];
    private const EXACT_FILES=[
        'backend/artisan',
        'backend/public/index.php','backend/public/.htaccess',
        'backend/public/manifest.webmanifest','backend/public/robots.txt',
        'backend/composer.json','backend/composer.lock',
        'backend/package.json','backend/package-lock.json',
        'backend/vite.config.js',
    ];

    private function trackedSource(string $relative):bool
    {
        if(in_array($relative,self::EXACT_FILES,true))return true;
        foreach(self::SOURCE_PREFIXES as $prefix){
            if(str_starts_with($relative,$prefix)
                && (str_ends_with($relative,'.php')
                    || str_ends_with($relative,'.js')
                    || str_ends_with($relative,'.css')
                    || str_ends_with($relative,'.json')
                    || str_ends_with($relative,'.sh'))){
                return true;
            }
        }
        return false;
    }

    public function compare(string $archivePath,?string $installedRoot=null):array
    {
        // Tests can supply an isolated fixture root. The production gate
        // always calls this with no override, using the running ERP source.
        $root=realpath($installedRoot??dirname(base_path()));
        if(!$root || !extension_loaded('phar')
            || !is_file($archivePath)||is_link($archivePath)
            || !is_readable($archivePath)){
            return [
                'ok'=>false,'compared'=>0,'missing_or_changed'=>1,
                'unexpected_source'=>0,
            ];
        }
        $archived=[];
        $mismatch=0;
        $totalSourceBytes=0;
        try{
            $phar=new PharData($archivePath);
            $entryCount=0;
            foreach(new RecursiveIteratorIterator($phar) as $item){
                if(!$item->isFile())continue;
                if(++$entryCount>15000)throw new \RuntimeException('Excessive archive entries.');
                $path=$item->getPathName();
                $needle='/boosted-export-erp/';
                $pos=strrpos($path,$needle);
                if($pos===false)throw new \RuntimeException('Source bundle prefix missing.');
                $relative=substr($path,$pos+strlen($needle));
                if($relative===''||str_contains($relative,'..')||
                    str_contains($relative,'\\')||str_starts_with($relative,'/')){
                    throw new \RuntimeException('Unsafe bundle path.');
                }
                if(!$this->trackedSource($relative))continue;
                $fileSize=$item->getSize();
                $totalSourceBytes+=$fileSize;
                if($fileSize>10485760 || $totalSourceBytes>134217728){
                    throw new \RuntimeException('Unreasonably large source file or expanded archive.');
                }
                if(isset($archived[$relative]))throw new \RuntimeException('Duplicate bundle source.');
                $archived[$relative]=true;
                $local=$root.DIRECTORY_SEPARATOR.$relative;
                if(!is_file($local)||is_link($local)||!is_readable($local)){
                    $mismatch++;
                    continue;
                }
                $inArchive=hash_file('sha256',$path);
                $onHost=hash_file('sha256',$local);
                if(!$inArchive||!$onHost||!hash_equals($inArchive,$onHost)){
                    $mismatch++;
                }
            }
        }catch(Throwable){
            return [
                'ok'=>false,'compared'=>count($archived),
                'missing_or_changed'=>$mismatch+1,'unexpected_source'=>0,
            ];
        }

        $unexpected=0;
        foreach(self::SOURCE_PREFIXES as $prefix){
            $directory=$root.DIRECTORY_SEPARATOR.$prefix;
            if(!is_dir($directory))continue;
            $iterator=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $item){
                if(!$item->isFile())continue;
                $relative=substr($item->getPathName(),strlen($root)+1);
                if($this->trackedSource($relative) && !isset($archived[$relative])){
                    $unexpected++;
                }
            }
        }
        foreach(self::EXACT_FILES as $file){
            if(is_file($root.DIRECTORY_SEPARATOR.$file)&&!isset($archived[$file])){
                $unexpected++;
            }
        }
        return [
            'ok'=>$mismatch===0 && $unexpected===0 && count($archived)>=50,
            'compared'=>count($archived),
            'missing_or_changed'=>$mismatch,
            'unexpected_source'=>$unexpected,
        ];
    }
}
