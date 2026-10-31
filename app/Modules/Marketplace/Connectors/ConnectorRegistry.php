<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use InvalidArgumentException;

/**
 * Resolves the connector of a marketplace. Classes come from config('marketplaces.connectors').
 */
class ConnectorRegistry
{
    /** @var array<string, MarketplaceConnector> */
    private array $resolved = [];

    public function for(string $marketplace): MarketplaceConnector
    {
        if (isset($this->resolved[$marketplace])) {
            return $this->resolved[$marketplace];
        }

        if ($marketplace === FakeConnector::MARKETPLACE && ! self::fakeEnabled()) {
            throw new InvalidArgumentException('Sàn giả lập đang tắt.');
        }

        $class = config("marketplaces.connectors.{$marketplace}");
        if (! $class) {
            throw new InvalidArgumentException("Sàn không được hỗ trợ: {$marketplace}");
        }

        return $this->resolved[$marketplace] = app($class);
    }

    public function has(string $marketplace): bool
    {
        return config("marketplaces.connectors.{$marketplace}") !== null
            && ($marketplace !== FakeConnector::MARKETPLACE || self::fakeEnabled());
    }

    /** @return array<int, string> */
    public function marketplaces(): array
    {
        return array_values(array_filter(array_keys(config('marketplaces.connectors', [])), fn ($m) => $this->has($m)));
    }

    public static function fakeEnabled(): bool
    {
        return (bool) config('marketplaces.fake.enabled');
    }
}
