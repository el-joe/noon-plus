<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name(),
            'image' => $this->image_path,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
