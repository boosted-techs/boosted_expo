<?php

namespace Tests\Feature;

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class R2MediaPrefixTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_media_writes_and_deletes_stay_under_the_boostedone_prefix(): void
    {
        $commands = [];
        $disk = Storage::build(array_merge(config('filesystems.disks.r2'), [
            'key' => 'test-access-key',
            'secret' => 'test-secret-key',
            'bucket' => 'shared-media',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'handler' => function (CommandInterface $command) use (&$commands) {
                $commands[] = [$command->getName(), $command['Key']];

                return Create::promiseFor(new Result);
            },
        ]));

        $disk->put('businesses/7/products/9/photo.webp', 'media', ['ContentType' => 'image/webp']);
        $disk->delete('businesses/7/products/9/photo.webp');

        $this->assertSame([
            ['PutObject', 'boostedone/production/media/businesses/7/products/9/photo.webp'],
            ['DeleteObject', 'boostedone/production/media/businesses/7/products/9/photo.webp'],
        ], $commands);
    }

    public function test_encrypted_media_supplies_the_key_for_both_writes_and_reads(): void
    {
        $commands = [];
        $key = str_repeat('k', 32);
        $disk = Storage::build(array_merge(config('filesystems.disks.r2'), [
            'key' => 'test-access-key',
            'secret' => 'test-secret-key',
            'bucket' => 'shared-media',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'options' => ['SSECustomerAlgorithm' => 'AES256', 'SSECustomerKey' => $key],
            'handler' => function (CommandInterface $command) use (&$commands) {
                $commands[] = [$command->getName(), $command['SSECustomerAlgorithm'], $command['SSECustomerKey']];

                return Create::promiseFor(new Result(['Body' => Utils::streamFor('private media')]));
            },
        ]));

        $disk->put('photo.webp', 'private media');
        $content = $disk->get('photo.webp');

        $this->assertSame('private media', $content);
        $this->assertSame([
            ['PutObject', 'AES256', base64_encode($key)],
            ['GetObject', 'AES256', base64_encode($key)],
        ], $commands);
    }
}
