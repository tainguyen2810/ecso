<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantUser */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => new RoleResource($this->role),
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'is_me' => $this->user_id === $request->user()?->id,
        ];
    }
}
