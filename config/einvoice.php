<?php

return [
    // "Nhà cung cấp giả lập": answers locally, for demos and tests. Never offered in production unless enabled.
    'fake_enabled' => (bool) env('EINVOICE_FAKE', env('APP_ENV', 'production') !== 'production'),

    // Seconds to wait for the provider's API.
    'timeout' => 20,

    // A pending invoice is asked again this many times, every `poll_seconds`, before it is left for "Cập nhật".
    'poll_tries' => 20,
    'poll_seconds' => 15,
];
