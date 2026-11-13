<?php

namespace App\Modules\Invoicing\Providers;

use App\Modules\Invoicing\Providers\Fake\FakeEInvoiceProvider;
use App\Modules\Invoicing\Providers\SePay\SePayProvider;
use InvalidArgumentException;

/** The e-invoice providers ECSO can talk to. */
class ProviderRegistry
{
    /** @var array<string, class-string<EInvoiceProvider>> */
    private const PROVIDERS = [
        SePayProvider::KEY => SePayProvider::class,
        FakeEInvoiceProvider::KEY => FakeEInvoiceProvider::class,
    ];

    /**
     * Shown on the page but not connectable yet, and why.
     *
     * @var array<string, array{label: string, note: string}>
     */
    public const UPCOMING = [
        'misa' => ['label' => 'MISA meInvoice', 'note' => 'Chờ MISA gửi tài liệu API chi tiết (trang công khai chưa có các lệnh gọi).'],
        'viettel' => ['label' => 'Viettel S-Invoice', 'note' => 'Viettel gửi tài liệu tích hợp sau khi ký hợp đồng dịch vụ.'],
        'vnpt' => ['label' => 'VNPT Invoice', 'note' => 'VNPT gửi tài liệu tích hợp sau khi ký hợp đồng dịch vụ.'],
    ];

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_filter(array_keys(self::PROVIDERS), fn ($k) => $k !== FakeEInvoiceProvider::KEY || config('einvoice.fake_enabled'));
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
