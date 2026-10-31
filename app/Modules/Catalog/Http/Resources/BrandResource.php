<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
