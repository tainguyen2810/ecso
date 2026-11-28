<?php

namespace App\Modules\Invoicing\Providers;

use App\Modules\Invoicing\Providers\Fake\FakeEInvoiceProvider;
use App\Modules\Invoicing\Providers\Misa\MisaProvider;
use App\Modules\Invoicing\Providers\SePay\SePayProvider;
use App\Modules\Invoicing\Providers\Viettel\ViettelProvider;
use App\Modules\Invoicing\Providers\Vnpt\VnptProvider;
use InvalidArgumentException;

/** The e-invoice providers ECSO can talk to. */
class ProviderRegistry
{
    /** @var array<string, class-string<EInvoiceProvider>> */
    private const PROVIDERS = [
        ViettelProvider::KEY => ViettelProvider::class,
        VnptProvider::KEY => VnptProvider::class,
        MisaProvider::KEY => MisaProvider::class,
        SePayProvider::KEY => SePayProvider::class,
        FakeEInvoiceProvider::KEY => FakeEInvoiceProvider::class,
    ];

    /**
     * Shown on the page but not connectable yet, and why.
     *
     * @return array<string, array{label: string, note: string}>
     */
    public function upcoming(): array
    {
        return MisaProvider::configured() ? [] : [
            MisaProvider::KEY => ['label' => 'MISA meInvoice', 'note' => 'Đang chờ MISA cấp mã ứng dụng (AppID) cho EzChat Bán hàng.'],
        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn ($k) => match ($k) {
            FakeEInvoiceProvider::KEY => (bool) config('einvoice.fake_enabled'),
            MisaProvider::KEY => MisaProvider::configured(),
            default => true,
        }));
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function get(string $key): EInvoiceProvider
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown e-invoice provider {$key}");
        }

        return app(self::PROVIDERS[$key]);
    }
}
