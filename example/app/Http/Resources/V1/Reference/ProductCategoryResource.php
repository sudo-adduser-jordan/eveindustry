<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item category with Tech I manufacturable products (manufacturing browser filter).
 */
class ProductCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'category_id' => (int) $this->resource['category_id'],
            'name' => (string) $this->resource['name'],
            /** Number of manufacturable products in the category. */
            'item_count' => (int) $this->resource['item_count'],
        ];
    }
}
