<?php

return [
    // "Hãng giả lập": a carrier that answers locally, for demos and tests. Never offered in production unless enabled.
    'fake_enabled' => (bool) env('SHIPPING_FAKE', env('APP_ENV', 'production') !== 'production'),

    // Shipments made through a carrier's API that heard nothing for this long are asked again (lost webhooks).
    'track_after_minutes' => (int) env('SHIPPING_TRACK_AFTER', 120),

    // Seconds to wait for a carrier's API.
    'timeout' => 15,

    // Weight used when neither the shop nor the products give one.
    'default_weight_g' => 500,
];
