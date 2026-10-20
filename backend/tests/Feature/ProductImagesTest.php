<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private function setUpOwner(string $code): array
    {
        $business = Business::create([
            'name'=>$code.' Exports', 'code'=>$code, 'country_code'=>'UG',
            'base_currency'=>'UGX', 'timezone'=>'Africa/Kampala', 'default_qr_level'=>'batch',
        ]);
        $owner = User::factory()->create();
        BusinessMembership::create([
            'business_id'=>$business->id, 'user_id'=>$owner->id, 'role'=>'owner', 'active'=>true,
        ]);
        $product = Product::create([
            'business_id'=>$business->id, 'sku'=>'AVO-1', 'name'=>'Avocado', 'base_unit'=>'kg',
        ]);

        return [$business, $owner, $product];
    }

    public function test_r2_image_is_resized_privately_and_tenant_scoped(): void
    {
        Storage::fake('r2');
        config()->set('filesystems.disks.r2.key', 'test-key');
        config()->set('filesystems.disks.r2.secret', 'test-secret');
        config()->set('filesystems.disks.r2.bucket', 'test-bucket');
        config()->set('filesystems.disks.r2.endpoint', 'https://example.invalid');

        [$business, $owner, $product] = $this->setUpOwner('FIRST');
        [$other, $otherOwner] = $this->setUpOwner('SECOND');

        $this->actingAs($owner)->withSession(['active_business_id'=>$business->id])
            ->post(route('products.images.store', $product->id), [
                'photo' => UploadedFile::fake()->image('avocado.png', 900, 600),
            ])->assertRedirect();

        $record = ProductImage::firstOrFail();
        Storage::disk('r2')->assertExists($record->original_key);
        Storage::disk('r2')->assertExists($record->thumbnail_key);
        $this->get(route('products.images.show', [
            'product'=>$product->id, 'image'=>$record->id, 'size'=>'thumb',
        ]))->assertOk()->assertHeader('Content-Type','image/webp');

        $this->actingAs($otherOwner)->withSession(['active_business_id'=>$other->id])
            ->get(route('products.images.show', [
                'product'=>$product->id, 'image'=>$record->id, 'size'=>'thumb',
            ]))->assertNotFound();
        $this->delete(route('products.images.destroy', [
            'product'=>$product->id, 'image'=>$record->id,
        ]))->assertNotFound();
        Storage::disk('r2')->assertExists($record->original_key);
    }

    public function test_upload_requires_r2_configuration(): void
    {
        [$business, $owner, $product] = $this->setUpOwner('FIRST');
        $this->actingAs($owner)->post(route('products.images.store', $product->id), [
            'photo' => UploadedFile::fake()->image('avocado.jpg', 500, 400),
        ])->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('product_images', 0);
    }
}
