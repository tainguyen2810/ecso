<?php

namespace App\Modules\Billing\Payments;

use InvalidArgumentException;

class GatewayRegistry
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $gateways = [BankTransferGateway::KEY => BankTransferGateway::class];

    /** @param class-string<PaymentGateway> $class */
    public function register(string $key, string $class): void
    {
        $this->gateways[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->gateways[$key]);
    }

    public function get(string $key): PaymentGateway
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Cổng thanh toán không tồn tại: {$key}");
        }

        return app($this->gateways[$key]);
    }

    public function default(): PaymentGateway
    {
        return $this->get((string) config('billing.gateway'));
    }
}
