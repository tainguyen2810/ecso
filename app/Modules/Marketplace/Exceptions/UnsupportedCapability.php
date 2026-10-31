<?php

namespace App\Modules\Marketplace\Exceptions;

use App\Modules\Marketplace\Connectors\Capability;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The marketplace does not offer the operation, or it is not verified yet (UNKNOWN). Never faked as success.
 */
class UnsupportedCapability extends RuntimeException
{
    public function __construct(public readonly string $marketplace, public readonly string $capability, public readonly string $state = Capability::UNSUPPORTED)
    {
        $reason = $state === Capability::UNKNOWN ? 'chưa được xác minh với tài liệu chính thức' : 'không được sàn hỗ trợ';
        parent::__construct("Thao tác {$capability} trên {$marketplace} {$reason}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'UNSUPPORTED_CAPABILITY',
            'capability' => $this->capability,
            'state' => $this->state,
        ], 422);
    }
}
