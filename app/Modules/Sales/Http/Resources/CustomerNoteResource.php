<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\CustomerNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerNote */
class CustomerNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'mine' => $this->created_by === $request->user()?->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
