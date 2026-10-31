<?php

namespace App\Modules\Billing\Payments;

use App\Modules\Billing\Models\Invoice;

/**
 * Bank transfer with the invoice's transfer code in the memo. There is no automatic notification: a platform
 * admin checks the bank statement and records the payment (with the bank transaction reference, which makes
 * the confirmation idempotent).
 */
class BankTransferGateway implements PaymentGateway
{
    public const KEY = 'bank_transfer';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Chuyển khoản ngân hàng';
    }

    public function instructions(Invoice $invoice): array
    {
        $bank = (array) config('billing.bank');
        $configured = filled($bank['name'] ?? null) && filled($bank['account_number'] ?? null) && filled($bank['account_name'] ?? null);

        return [
            'configured' => $configured,
            'method' => self::KEY,
            'bank_name' => $bank['name'] ?? null,
            'account_number' => $bank['account_number'] ?? null,
            'account_name' => $bank['account_name'] ?? null,
            'branch' => $bank['branch'] ?? null,
            'amount' => $invoice->amount,
            'memo' => $invoice->transfer_code,
        ];
    }

    public function parseNotification(string $body, array $headers): ?PaymentNotification
    {
        return null;
    }
}
