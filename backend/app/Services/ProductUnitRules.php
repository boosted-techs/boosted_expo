<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductUnitRules
{
    public function ensureUnchangedWhenReferenced(Product $product, string $newUnit): void
    {
        if ($newUnit === $product->base_unit) {
            return;
        }

        // A unit change here would re-interpret historical purchase, receiving,
        // packing or customer sales quantities. Create a new product instead.
        foreach ([
            'supply_request_items', 'stock_batches', 'packing_jobs', 'purchase_orders',
            'sales_request_items', 'sales_quotation_lines', 'sales_order_lines',
        ] as $table) {
            if (DB::table($table)->where('business_id', $product->business_id)
                ->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages([
                    'base_unit' => 'The unit for '.$product->name.' cannot change because this product is already used in business records. Create a new product for the new unit.',
                ]);
            }
        }
    }
}
