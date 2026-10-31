<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide mapping raw marketplace status -> internal status. No tenant data. */
class MarketplaceStatusMap extends Model
{
    protected $fillable = ['marketplace', 'raw_status', 'normalized_status', 'note'];
}
