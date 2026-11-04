<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\CustomerImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerImport */
class CustomerImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'file_name' => $this->file_name,
            'status' => $this->status,
            'total' => $this->total,
            'processed' => $this->processed(),
            'created' => $this->created_count,
            'updated' => $this->updated_count,
            'error_count' => $this->error_count,
            'errors' => $this->errors ?? [],
            'message' => $this->message,
            'by' => $this->user?->name,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
