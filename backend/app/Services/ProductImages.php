<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImages
{
    public function configured(): bool
    {
        $r2 = config('filesystems.disks.r2', []);

        return filled($r2['key'] ?? null)
            && filled($r2['secret'] ?? null)
            && filled($r2['bucket'] ?? null)
            && filled($r2['endpoint'] ?? null);
    }

    private function resize(\GdImage $source, int $max, int $width, int $height): string
    {
        $scale = min(1, $max / max($width, $height));
        $destWidth = max(1, (int) round($width * $scale));
        $destHeight = max(1, (int) round($height * $scale));
        $dest = imagecreatetruecolor($destWidth, $destHeight);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $destWidth, $destHeight, $width, $height);
        ob_start();
        imagewebp($dest, null, 83);
        $blob = ob_get_clean();
        imagedestroy($dest);
        if ($blob === false) throw new \RuntimeException('Unable to process image.');

        return $blob;
    }

    public function store(Product $product, UploadedFile $file): ProductImage
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['photo' => 'Media uploads are unavailable until Cloudflare R2 is configured.']);
        }

        $info = @getimagesize($file->getRealPath());
        if (! is_array($info) || ! in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) {
            throw ValidationException::withMessages(['photo' => 'Please upload a valid JPEG, PNG or WebP image.']);
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 16000000) {
            throw ValidationException::withMessages(['photo' => 'Image dimensions are outside the allowed range.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) throw ValidationException::withMessages(['photo' => 'This image could not be opened.']);
        try {
            $full = $this->resize($source, 1600, $width, $height);
            $small = $this->resize($source, 320, $width, $height);
        } finally {
            imagedestroy($source);
        }

        $base = "businesses/{$product->business_id}/products/{$product->id}/".Str::uuid();
        $original = $base.'/photo.webp';
        $thumbnail = $base.'/thumb.webp';

        try {
            Storage::disk('r2')->put($original, $full, ['ContentType' => 'image/webp']);
            Storage::disk('r2')->put($thumbnail, $small, ['ContentType' => 'image/webp']);
            return ProductImage::create([
                'business_id' => $product->business_id, 'product_id' => $product->id,
                'original_key' => $original, 'thumbnail_key' => $thumbnail,
                'width' => $width, 'height' => $height,
            ]);
        } catch (\Throwable $error) {
            Storage::disk('r2')->delete([$original, $thumbnail]);
            throw $error;
        }
    }
}