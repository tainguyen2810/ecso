<?php

namespace App\Modules\Billing\Payments;

use App\Modules\Billing\Models\Invoice;

/**
 * A way for tenants to pay invoices. New gateways (VNPay, PayOS, MoMo…) plug in here once their merchant
 * credentials and official documentation are available; until then they do not exist in code (UNKNOWN).
 */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /**
     * What the payer must do (bank details, a payment URL…). "configured" is false when the operator has not
     * set the gateway up yet.
     *
     * @return array{configured: bool, method: string}&array<string, mixed>
     */
    public function instructions(Invoice $invoice): array;

    /**
     * Verifies and parses a server-to-server notification. Returns null when the gateway has no notifications
     * (bank transfer: an admin confirms the payment). Throws InvalidPaymentNotification on a bad signature.
     *
     * @param  array<string, mixed>  $headers
     */
    public function parseNotification(string $body, array $headers): ?PaymentNotification;
}
