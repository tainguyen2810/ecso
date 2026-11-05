<?php

namespace App\Modules\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ward, commune or special zone, directly under its province (no district level since 1 July 2025). `former` lists
 * the pre-2025 wards it absorbed ([ward, district]) so an old name still finds it; addresses never store old units.
 */
class Ward extends Model
{
    protected $table = 'geo_wards';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['former' => 'array'];
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
}
