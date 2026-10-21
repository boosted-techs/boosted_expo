<?php

namespace Tests\Feature;

use App\Services\EncryptedBackup;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncryptedBackupTest extends TestCase
{
    use DatabaseTruncation;

    private string $working;

    protected function setUp():void
    {
        parent::setUp();
        $this->working=sys_get_temp_dir().'/expo-encrypted-qa-'.Str::random(14);
        mkdir($this->working,0700,true);
        config()->set('expo_operations.backup_directory',$this->working.'/encrypted');
        config()->set('expo_operations.backup_key',base64_encode(random_bytes(32)));
        config()->set('expo_operations.offsite_enabled',false);
    }

    protected function tearDown():void
    {
        if(isset($this->working) && is_dir($this->working)){
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->working);
        }
        parent::tearDown();
    }

    public function test_encrypted_backup_can_be_authentically_verified_and_staged_without_overwriting_live_database():void
    {
        $backup=app(EncryptedBackup::class);
        $created=$backup->create();
        $this->assertTrue($created['verified']);
        $this->assertSame('disabled',$created['offsite']['status']);
        $this->assertFileExists($created['path']);
        $this->assertSame(0,fileperms($created['path'])&0007);
        $manifest=$backup->verify($created['path']);
        $this->assertSame(config('database.default'),$manifest['database_driver']);
        $this->assertArrayHasKey($manifest['database_file'],$manifest['files']);

        $folder=$this->working.'/restored';
        $backup->extractForRecovery($created['path'],$folder);
        $this->assertFileExists($folder.'/'.$manifest['database_file']);
        $this->expectException(\RuntimeException::class);
        $backup->extractForRecovery($created['path'],$folder);
    }

    public function test_tampered_ciphertext_is_rejected_and_staging_refuses_application_directory():void
    {
        $backup=app(EncryptedBackup::class);
        $created=$backup->create();
        $tampered=$this->working.'/corrupted.expoenc';
        copy($created['path'],$tampered);
        $handle=fopen($tampered,'r+b');
        fseek($handle,70);
        $byte=fread($handle,1);
        fseek($handle,70);
        fwrite($handle,chr(ord($byte)^0x20));
        fclose($handle);
        try{
            $backup->verify($tampered);
            $this->fail('Tampered encrypted backup was accepted.');
        }catch(\RuntimeException $e){
            $this->assertNotSame('',$e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $backup->extractForRecovery($created['path'],base_path('unapproved-restore'));
    }

    public function test_private_offsite_object_is_read_back_and_compared_without_exposing_plaintext():void
    {
        Storage::fake('expo_backup_offsite');
        config()->set('expo_operations.offsite_enabled',true);
        config()->set('expo_operations.offsite_prefix','qa/archives');
        $created=app(EncryptedBackup::class)->create();
        $this->assertSame('verified',$created['offsite']['status']);
        $remoteKey=$created['offsite']['object_key'];
        Storage::disk('expo_backup_offsite')->assertExists($remoteKey);
        $this->assertSame(hash_file('sha256',$created['path']),
            $created['offsite']['sha256']);
        $this->assertStringStartsWith('EXPOENC1',
            Storage::disk('expo_backup_offsite')->get($remoteKey));
        $this->assertNotSame(file_get_contents($created['path']),
            'Unencrypted production database bytes');
    }
}
