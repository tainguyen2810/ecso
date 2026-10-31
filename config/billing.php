<?php

return [
    // Payment gateway used for subscription invoices. Only bank transfer confirmed by a platform admin is
    // implemented; online gateways (VNPay, PayOS, MoMo) are UNKNOWN until merchant credentials and docs exist.
    'gateway' => env('BILLING_GATEWAY', 'bank_transfer'),

    // Bank account shown on invoices. Empty = invoices say transfer details are not configured yet.
    'bank' => [
        'name' => env('BILLING_BANK_NAME'),
        'account_number' => env('BILLING_BANK_ACCOUNT_NUMBER'),
        'account_name' => env('BILLING_BANK_ACCOUNT_NAME'),
        'branch' => env('BILLING_BANK_BRANCH'),
    ],

    'invoice_due_days' => 7,
    // A renewal invoice is issued this many days before the paid period ends.
    'renewal_notice_days' => 7,
    // After the period ends unpaid, features are kept this long before moving the tenant to Free.
    'grace_days' => 3,
    // Upgrade credit and prices are rounded to this many VND.
    'rounding' => 1000,
];
