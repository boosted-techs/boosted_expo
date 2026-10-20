<?php
namespace App\Services;
use App\Models\Product;
use App\Models\QrIdentity;
use Illuminate\Support\Str;

class ProductQrCodes
{
    public function ensure(Product $product): QrIdentity
    {
        return QrIdentity::firstOrCreate(
            ['business_id'=>$product->business_id,'level'=>'product','record_id'=>$product->id],
            ['public_token'=>(string) Str::uuid(),'public_enabled'=>false],
        );
    }
}