<?php

return [
    // "Nhà cung cấp giả lập": answers locally, for demos and tests. Never offered in production unless enabled.
    'fake_enabled' => (bool) env('EINVOICE_FAKE', env('APP_ENV', 'production') !== 'production'),

    // Seconds to wait for the provider's API.
    'timeout' => 20,

    // A pending invoice is asked again this many times, every `poll_seconds`, before it is left for "Cập nhật".
    'poll_tries' => 20,
    'poll_seconds' => 15,

    // Viettel S-Invoice: one address for every shop (test accounts too).
    'viettel' => [
        'base' => env('EINVOICE_VIETTEL_BASE', 'https://api-vinvoice.viettel.vn/services/einvoiceapplication/api'),
    ],

    // MISA meInvoice: the AppID MISA gives our software as a partner. Without it MISA shows as "sắp có".
    'misa' => [
        'app_id' => env('EINVOICE_MISA_APP_ID'),
        'base' => env('EINVOICE_MISA_BASE', 'https://api.meinvoice.vn/api/v3/'),
        'sandbox' => env('EINVOICE_MISA_SANDBOX', 'https://testapi.meinvoice.vn/api/v3/'),
    ],
];
