<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'status' => $this->status,
            'role' => $this->whenHas('role_key'),
            'setup_completed' => $this->setup_completed_at !== null,
            'channels' => $this->settings['setup']['channels'] ?? null,
            'team_size' => $this->settings['setup']['team_size'] ?? null,
            'is_demo' => (bool) $this->is_demo,
            'demo_status' => $this->demo_status,
            'demo_expires_at' => $this->demo_expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
