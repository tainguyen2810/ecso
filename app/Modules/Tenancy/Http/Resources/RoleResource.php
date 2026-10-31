<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Access\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissionKeys()),
            'assignable' => $this->when($this->resource->getAttribute('assignable') !== null, fn () => (bool) $this->resource->getAttribute('assignable')),
        ];
    }
}
