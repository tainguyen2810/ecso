<?php

namespace App\Modules\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A province or centrally-run city of Vietnam since 1 July 2025 (34 units, Resolution 202/2025/QH15), keyed by the
 * General Statistics Office code. Shared by every business; loaded from database/data/vn-admin-units.json.
 *
 * @property string $code
 * @property string $name
 * @property string $short_name
 * @property string $type
 * @property list<string>|null $former
 */
class Province extends Model
{
    protected $table = 'geo_provinces';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['former' => 'array'];
    }

    /** @return HasMany<Ward, $this> */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class, 'province_code', 'code');
    }
}
