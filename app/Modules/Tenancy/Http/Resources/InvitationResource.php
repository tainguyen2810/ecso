<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantInvitation */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            'role' => new RoleResource($this->role),
            'invited_by' => $this->inviter?->name,
            'expires_at' => $this->expires_at->toIso8601String(),
            'status' => match (true) {
                $this->accepted_at !== null => 'accepted',
                $this->revoked_at !== null => 'revoked',
                $this->expires_at->isPast() => 'expired',
                default => 'pending',
            },
        ];
    }
}
