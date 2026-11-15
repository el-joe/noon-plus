<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Category;
use App\Shared\Http\PublicUrl;
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
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name(),
            'image' => PublicUrl::of($this->image_path),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
