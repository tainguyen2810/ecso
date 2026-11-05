<?php

namespace App\Modules\Geo\Services;

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Geo\Support\AdminUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Standard Vietnamese addresses: street + ward + province of the units in force since 1 July 2025 (two levels).
 *
 * resolve() turns the parts sent by a form into what is stored (codes, street, and the full text address composed
 * from them); suggest() reads a typed or pasted one-line address ("88 Nơ Trang Long, P.11, Bình Thạnh, HCM") and
 * proposes the standard parts, also from pre-2025 ward, district and province names.
 */
class VnAddress
{
    /** Carriers the shop ships with: an order handed to one of them needs a standard address. */
    public const CARRIERS = ['GHN', 'Viettel Post', 'SPX Express', 'J&T Express'];

    /** Short names people type for a province, besides its own and its former names. */
    private const PROVINCE_ALIASES = [
        '79' => ['hcm', 'tphcm', 'tp hcm', 'sai gon', 'sg', 'ho chi minh', 'thanh pho ho chi minh'],
        '01' => ['hn', 'ha noi', 'tp hn'],
        '48' => ['da nang'],
        '31' => ['hai phong'],
        '92' => ['can tho'],
        '46' => ['hue', 'thua thien hue', 'tt hue'],
    ];

    /** @var Collection<string, Province>|null */
    private ?Collection $provinces = null;

    /** @var array<string, Collection<int, Ward>> */
    private array $wards = [];

    /**
     * Rules for the address parts of a request, under an optional key prefix ("shipping_", "customer.").
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'street' => ['nullable', 'string', 'max:255'],
            $prefix.'province_code' => ['nullable', 'string', 'max:3'],
            $prefix.'ward_code' => ['nullable', 'string', 'max:6'],
        ];
    }

    /**
     * The stored form of an address. With a ward the address is standard: the province follows from the ward and
     * the text address is composed ("12 Nguyễn Huệ, Phường Sài Gòn, Thành phố Hồ Chí Minh"). Without one the free
     * text is kept as typed and the codes stay empty (or the province only).
     *
     * @param  string  $errorPrefix  key prefix of the validation messages, matching the request fields
     * @return array{street: ?string, province_code: ?string, ward_code: ?string, address: ?string}
     */
    public function resolve(?string $street, ?string $provinceCode, ?string $wardCode, ?string $freeText = null, string $errorPrefix = ''): array
    {
        $street = filled($street) ? trim((string) $street) : null;

        if (filled($wardCode)) {
            $ward = Ward::query()->find($wardCode)
                ?? throw ValidationException::withMessages([$errorPrefix.'ward_code' => 'Phường/xã không có trong danh mục.']);
            if (filled($provinceCode) && $provinceCode !== $ward->province_code) {
                throw ValidationException::withMessages([$errorPrefix.'ward_code' => "{$ward->name} không thuộc tỉnh/thành đã chọn."]);
            }
            $province = $this->province($ward->province_code);

            return ['street' => $street, 'province_code' => $ward->province_code, 'ward_code' => $ward->code, 'address' => self::compose($street, $ward->name, $province?->name)];
        }

        if (filled($provinceCode)) {
            $province = $this->province((string) $provinceCode)
                ?? throw ValidationException::withMessages([$errorPrefix.'province_code' => 'Tỉnh/thành không có trong danh mục.']);

            return ['street' => $street, 'province_code' => $province->code, 'ward_code' => null, 'address' => self::compose($street, null, $province->name)];
        }

        $text = filled($freeText) ? trim((string) $freeText) : $street;

        return ['street' => $street, 'province_code' => null, 'ward_code' => null, 'address' => $text];
    }

    /**
     * Applies the address parts of a request to a model: the standard parts when any is sent, otherwise a plain
     * text address replaces the old one and clears the codes (they would no longer describe it).
     *
     * @param  array<string, mixed>  $data
     * @param  string  $prefix  column prefix of street/province_code/ward_code ("shipping_" on orders)
     * @param  string  $inputPrefix  key prefix of those parts in $data
     * @return bool whether the address was touched
     */
    public function apply(Model $model, array $data, string $prefix = '', string $addressColumn = 'address', string $inputPrefix = ''): bool
    {
        $keys = [$inputPrefix.'street', $inputPrefix.'province_code', $inputPrefix.'ward_code'];
        if (array_intersect($keys, array_keys($data)) !== []) {
            $parts = $this->resolve($data[$keys[0]] ?? null, $data[$keys[1]] ?? null, $data[$keys[2]] ?? null, $data[$addressColumn] ?? null, $inputPrefix);
        } elseif (array_key_exists($addressColumn, $data)) {
            $parts = ['street' => null, 'province_code' => null, 'ward_code' => null, 'address' => filled($data[$addressColumn]) ? trim((string) $data[$addressColumn]) : null];
        } else {
            return false;
        }
        $model->forceFill([
            $prefix.'street' => $parts['street'],
            $prefix.'province_code' => $parts['province_code'],
            $prefix.'ward_code' => $parts['ward_code'],
            $addressColumn => $parts['address'],
        ]);

        return true;
    }

    /**
     * The address parts of a model, to copy onto another (customer -> order, customer -> saved address).
     *
     * @return array{street: ?string, province_code: ?string, ward_code: ?string}
     */
    public static function partsOf(Model $model, string $prefix = ''): array
    {
        return ['street' => $model->{$prefix.'street'}, 'province_code' => $model->{$prefix.'province_code'}, 'ward_code' => $model->{$prefix.'ward_code'}];
    }

    public static function compose(?string $street, ?string $ward, ?string $province): ?string
    {
        $parts = array_values(array_filter([trim((string) $street), $ward, $province], fn ($p) => filled($p)));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Reads a one-line address. Returns the standard parts it recognises (the ward only when one ward clearly
     * matches) or null when not even a province is found.
     *
     * @return array{street: ?string, province_code: string, province_name: string, ward_code: ?string, ward_name: ?string, address: ?string, via_former: bool}|null
     */
    public function suggest(string $text): ?array
    {
        $text = self::expandAbbreviations(trim($text));
        if ($text === '') {
            return null;
        }
        // Words of the text with their offsets, and their folded form for matching.
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE);
        $offsets = array_map(fn ($w) => $w[1], $m[0]);
        $words = array_map(fn ($w) => AdminUnits::fold($w[0]), $m[0]);
        if ($words === []) {
            return null;
        }

        // Province: the alias found last in the text (addresses end with the province), the longer one on a tie.
        $province = null;
        $provinceAt = null;
        foreach ($this->provinces() as $p) {
            $aliases = array_merge([AdminUnits::fold($p->name), AdminUnits::fold($p->short_name)], array_map(fn ($f) => AdminUnits::fold($f), $p->former ?? []), self::PROVINCE_ALIASES[$p->code] ?? []);
            foreach (array_unique($aliases) as $alias) {
                $at = self::lastIndex($words, explode(' ', $alias));
                if ($at !== null && ($provinceAt === null || $at > $provinceAt || ($at === $provinceAt && count(explode(' ', $alias)) > 1))) {
                    [$province, $provinceAt] = [$p, $at];
                }
            }
        }

        // Ward: the best scored candidate of the province (of all provinces when none was named).
        /** @var array<string, array{ward: Ward, score: int, start: int, former: bool}> $best */
        $best = [];
        $candidates = $province ? $this->wardsOf($province->code) : $this->provinces()->flatMap(fn (Province $p) => $this->wardsOf($p->code));
        foreach ($candidates as $ward) {
            foreach ($this->wardPatterns($ward) as [$score, $phrases, $former]) {
                $starts = [];
                foreach ($phrases as $phrase) {
                    $at = self::lastIndex($words, $phrase);
                    if ($at === null) {
                        continue 2;
                    }
                    $starts[] = $at;
                }
                $start = min($starts);
                // The province's own name ("..., Bình Dương") is not also a ward ("Phường Bình Dương") unless spelled so.
                if ($score < 100 && in_array($provinceAt, $starts, true)) {
                    continue;
                }
                if (! isset($best[$ward->code]) || $best[$ward->code]['score'] < $score) {
                    $best[$ward->code] = ['ward' => $ward, 'score' => $score, 'start' => $start, 'former' => $former];
                }
            }
        }
        $top = null;
        $tie = false;
        foreach ($best as $candidate) {
            if ($top === null || $candidate['score'] > $top['score']) {
                [$top, $tie] = [$candidate, false];
            } elseif ($candidate['score'] === $top['score']) {
                $tie = true;
            }
        }
        // Two different wards equally likely (e.g. "Phường 1" of two old districts): do not guess.
        if ($tie) {
            $top = null;
        }

        if ($top !== null && $province === null) {
            $province = $this->province($top['ward']->province_code);
        }
        if ($province === null) {
            return null;
        }

        $cut = min(array_filter([$top['start'] ?? null, $provinceAt], fn ($v) => $v !== null));
        $street = trim(substr($text, 0, $offsets[$cut]), " \t,;.-/");
        $street = $street === '' ? null : $street;
        $ward = $top['ward'] ?? null;

        return [
            'street' => $street,
            'province_code' => $province->code,
            'province_name' => $province->name,
            'ward_code' => $ward?->code,
            'ward_name' => $ward?->name,
            'address' => self::compose($street, $ward?->name, $province->name),
            'via_former' => (bool) ($top['former'] ?? false),
        ];
    }

    public function province(string $code): ?Province
    {
        return $this->provinces()->get($code);
    }

    /** @return Collection<string, Province> */
    public function provinces(): Collection
    {
        return $this->provinces ??= Province::query()->orderBy('code')->get()->keyBy('code');
    }

    /** @return Collection<int, Ward> */
    public function wardsOf(string $provinceCode): Collection
    {
        return $this->wards[$provinceCode] ??= Ward::query()->where('province_code', $provinceCode)->orderBy('name')->get();
    }

    /**
     * Ways a ward shows up in a text, best first: [score, phrases that must all be present, found by a former name].
     *
     * @return list<array{0: int, 1: list<list<string>>, 2: bool}>
     */
    private function wardPatterns(Ward $ward): array
    {
        $full = AdminUnits::fold($ward->name);
        $bare = self::bare($full);
        $patterns = [[100, [explode(' ', $full)], false]];
        if (self::distinctive($bare)) {
            $patterns[] = [60, [explode(' ', $bare)], false];
        }
        foreach ($ward->former ?? [] as [$oldWard, $oldDistrict]) {
            $oldWard = AdminUnits::fold((string) $oldWard);
            $district = AdminUnits::fold((string) $oldDistrict);
            // "Quận 11" stays whole (a bare "11" would match the ward number), "Quận Bình Thạnh" may be just "Bình Thạnh".
            $district = preg_match('/^\d+$/', self::bare($district)) ? $district : self::bare($district);
            if ($district !== '') {
                $patterns[] = [90, [explode(' ', $oldWard), explode(' ', $district)], true];
                if (self::distinctive(self::bare($oldWard))) {
                    $patterns[] = [85, [explode(' ', self::bare($oldWard)), explode(' ', $district)], true];
                }
            }
            if (self::distinctive(self::bare($oldWard))) {
                $patterns[] = [50, [explode(' ', self::bare($oldWard))], true];
            }
        }

        return $patterns;
    }

    /** "phuong ben nghe" -> "ben nghe"; "quan 1" -> "1". */
    private static function bare(string $folded): string
    {
        return (string) preg_replace('/^(phuong|xa|dac khu|thi tran|quan|huyen|thi xa|thanh pho)\s+/', '', $folded);
    }

    /** A bare name is safe to match alone when it is not just a number and has some length ("ben nghe", not "1"). */
    private static function distinctive(string $bare): bool
    {
        return ! preg_match('/^\d+$/', $bare) && mb_strlen($bare) >= 5 && str_contains($bare, ' ');
    }

    /**
     * Last position where the phrase occurs as consecutive words.
     *
     * @param  list<string>  $words
     * @param  list<string>  $phrase
     */
    private static function lastIndex(array $words, array $phrase): ?int
    {
        $n = count($phrase);
        for ($i = count($words) - $n; $i >= 0; $i--) {
            if (array_slice($words, $i, $n) === $phrase) {
                return $i;
            }
        }

        return null;
    }

    /** "P.11" / "p11" / "F11" -> "Phường 11", "Q.1" -> "Quận 1", "TP." -> "Thành phố", "X." -> "Xã" ... */
    private static function expandAbbreviations(string $text): string
    {
        $rules = [
            '/(?<![\p{L}\p{N}])tp\.?\s*(?=hcm\b)/iu' => 'TP ',
            '/(?<![\p{L}\p{N}])t\.?p\.\s*/iu' => 'Thành phố ',
            '/(?<![\p{L}\p{N}])tx\.\s*/iu' => 'Thị xã ',
            '/(?<![\p{L}\p{N}])tt\.\s*/iu' => 'Thị trấn ',
            '/(?<![\p{L}\p{N}])(?:p|f)\.?\s*(?=\d)/iu' => 'Phường ',
            '/(?<![\p{L}\p{N}])p\.\s*/iu' => 'Phường ',
            '/(?<![\p{L}\p{N}])q\.?\s*(?=\d)/iu' => 'Quận ',
            '/(?<![\p{L}\p{N}])q\.\s*/iu' => 'Quận ',
            '/(?<![\p{L}\p{N}])h\.\s*/iu' => 'Huyện ',
            '/(?<![\p{L}\p{N}])x\.\s*/iu' => 'Xã ',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $text);
    }
}
