<?php

namespace App\Modules\Marketplace\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The platform has no app credentials for this marketplace yet (set by the operator, not by tenants). */
class CredentialsMissing extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing  config keys that are empty
     */
    public function __construct(public readonly string $marketplace, public readonly array $missing)
    {
        parent::__construct("Hệ thống chưa được cấu hình app credentials cho {$marketplace}. Liên hệ quản trị viên nền tảng.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'CREDENTIALS_MISSING',
            'marketplace' => $this->marketplace,
        ], 422);
    }
}
