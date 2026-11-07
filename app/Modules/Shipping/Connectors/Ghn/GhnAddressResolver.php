<?php

namespace App\Modules\Shipping\Connectors\Ghn;

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Shipping\Connectors\CarrierException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * EzSell stores addresses as province + ward (2 levels, since 07/2025); GHN's API asks for its own district id and
 * ward code. The ward is found by name inside the province in GHN's address book (cached a day per environment).
 * A ward GHN does not know yet is reported to the shop, never guessed.
 */
class GhnAddressResolver
{
    public function __construct(private readonly GhnClient $client) {}

    /** @return array{district_id: int, ward_code: string} */
    public function resolve(string $token, string $environment, ?string $provinceCode, ?string $wardCode): array
    {
        $ward = $wardCode !== null ? Ward::query()->where('code', $wardCode)->first() : null;
        $province = $provinceCode !== null ? Province::query()->where('code', $provinceCode)->first() : null;
        if ($ward === null || $province === null) {
            throw new CarrierException('Địa chỉ nhận thiếu tỉnh/thành hoặc phường/xã.');
        }

        $book = $this->wardsOf($token, $environment, $province->name);
        $hit = $book[self::plain($ward->name)] ?? null;
        if ($hit === null) {
            throw new CarrierException("GHN chưa có phường/xã \"{$ward->name}\" ({$province->name}) trong danh mục. Sửa địa chỉ hoặc hỏi GHN.");
        }

        return $hit;
    }

    /** @return array<string, array{district_id: int, ward_code: string}> plain ward name => GHN ids */
    private function wardsOf(string $token, string $environment, string $provinceName): array
    {
        return Cache::remember('ghn:wards:'.$environment.':'.md5(self::plain($provinceName)), now()->addDay(), function () use ($token, $environment, $provinceName) {
            $provinces = (array) $this->client->post($token, $environment, '/master-data/province');
            $province = collect($provinces)->first(fn ($p) => self::plain((string) ($p['ProvinceName'] ?? '')) === self::plain($provinceName)
                || in_array(self::plain($provinceName), array_map(fn ($n) => self::plain((string) $n), (array) ($p['NameExtension'] ?? [])), true));
            if ($province === null) {
                throw new CarrierException("GHN chưa có tỉnh/thành \"{$provinceName}\" trong danh mục.");
            }
            $book = [];
            foreach ((array) $this->client->post($token, $environment, '/master-data/district', ['province_id' => (int) $province['ProvinceID']]) as $district) {
                foreach ((array) $this->client->post($token, $environment, '/master-data/ward', ['district_id' => (int) $district['DistrictID']]) as $w) {
                    $names = [(string) ($w['WardName'] ?? ''), ...array_map('strval', (array) ($w['NameExtension'] ?? []))];
                    foreach ($names as $name) {
                        $book[self::plain($name)] ??= ['district_id' => (int) $district['DistrictID'], 'ward_code' => (string) $w['WardCode']];
                    }
                }
            }

            return $book;
        });
    }

    /** "Phường Sài Gòn" and "P. Sài Gòn" and "sai gon" are the same name. */
    public static function plain(string $name): string
    {
        $s = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();
        $s = preg_replace('/^(thanh pho|tinh|tp|phuong|xa|thi tran|dac khu|p|x|tt)\s+/', '', $s) ?? $s;

        return str_replace(' ', '', $s);
    }
}
