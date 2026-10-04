<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'stock' => $this->stock,
            'category' => $this->category,
            // Las imágenes propias se guardan como ruta relativa (/images/products/...)
            // y se devuelven como URL absoluta del servidor de la API.
            'image_url' => $this->image_url && str_starts_with($this->image_url, '/')
                ? url($this->image_url)
                : $this->image_url,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
