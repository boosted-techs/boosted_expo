<?php

namespace App\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class ReleaseBuildVerifier
{
    /**
     * Read-only, deterministic SHA-256 over the exact Vite public/build tree.
     * No live HTTP requests, code execution or filesystem changes.
     */
    public function inspect(?string $directory=null):array
    {
        $directory??=public_path('build');
        $result=['ok'=>false,'sha256'=>null,'files'=>0,'issue'=>'Missing production frontend build.'];
        if(is_link($directory)||!is_dir($directory)||!is_readable($directory)){
            return $result;
        }
        $base=realpath($directory);
        if(!$base)return $result;
        if(is_file(dirname($base).'/hot')){
            $result['issue']='Vite development hot reload marker is present.';
            return $result;
        }
        $files=[];
        $bytes=0;
        try{
            $walk=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
            foreach($walk as $entry){
                if(count($files)>=2048 || $entry->isLink() || !$entry->isFile()){
                    throw new \RuntimeException('Unexpected symlink or excessive build files.');
                }
                $absolute=$entry->getPathname();
                $relative=substr($absolute,strlen($base)+1);
                if(!preg_match('~^(?:(?:manifest|fonts-manifest)\.json|assets/[a-zA-Z0-9_.-]+\.(?:js|css|woff2?|svg|png|jpg|jpeg|webp|gif|ico))$~D',$relative)){
                    throw new \RuntimeException('Unexpected or executable file in public build.');
                }
                $bytes+=$entry->getSize();
                if($bytes>536870912)throw new \RuntimeException('Public build exceeds 512 MiB.');
                $digest=hash_file('sha256',$absolute);
                if(!is_string($digest))throw new \RuntimeException('Cannot fingerprint asset.');
                $files[$relative]=$digest;
            }
            ksort($files,SORT_STRING);
            $manifest=$base.'/manifest.json';
            if(!array_key_exists('manifest.json',$files)) {
                throw new \RuntimeException('Vite manifest is missing.');
            }
            $entries=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($entries) || !isset($entries['resources/css/app.css'], $entries['resources/js/app.js'])){
                throw new \RuntimeException('Required Vite entrypoints are missing.');
            }
            foreach(['resources/css/app.css','resources/js/app.js'] as $required){
                if(($entries[$required]['isEntry']??false)!==true){
                    throw new \RuntimeException('Required frontend Vite entry is not an entrypoint.');
                }
            }
            foreach($entries as $entry){
                if(!is_array($entry) || !is_string($entry['file']??null)
                    || !isset($files[$entry['file']])){
                    throw new \RuntimeException('Vite manifest refers to missing or unsupported asset.');
                }
                foreach(($entry['css']??[]) as $css){
                    if(!is_string($css)||!isset($files[$css])){
                        throw new \RuntimeException('Vite entry references missing stylesheet.');
                    }
                }
                foreach(($entry['assets']??[]) as $asset){
                    if(!is_string($asset)||!isset($files[$asset])){
                        throw new \RuntimeException('Vite entry references missing asset.');
                    }
                }
            }
            if(count($files)<3)throw new \RuntimeException('Empty or incomplete frontend build.');
            $result=[
                'ok'=>true,
                'sha256'=>hash('sha256',json_encode(
                    ['format'=>'expo-build-v1','files'=>$files],
                    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
                'files'=>count($files),
                'issue'=>null,
            ];
        }catch(Throwable $e){
            $result=['ok'=>false,'sha256'=>null,'files'=>count($files),
                'issue'=>$e->getMessage()];
        }
        return $result;
    }
}
