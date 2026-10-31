<?php

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * INVENTORY-ENGINE.md: every stock change goes through InventoryService.
 */
const STOCK_WRITERS = ['app/Modules/Inventory/Services/InventoryService.php'];

it('writes stock tables only from InventoryService', function () {
    $offenders = [];
    $pattern = '/(table\(\s*[\'"]stock_(balances|movements|reservations)[\'"]\s*\)[^;]*->(update|insert\w*|upsert|delete|increment|decrement|truncate)\s*\()'
        .'|((StockBalance|StockMovement|StockReservation)::[^;]*->(update|delete|increment|decrement|forceFill|save)\s*\()'
        .'|(new\s+(StockBalance|StockMovement|StockReservation)\b)/s';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = Str::after($file->getPathname(), base_path().'/');
        if (in_array($relative, STOCK_WRITERS, true)) {
            continue;
        }
        if (preg_match($pattern, file_get_contents($file->getPathname()))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

arch('stock ledger models are only used inside the Inventory module')
    ->expect(['App\Modules\Inventory\Models\StockBalance', 'App\Modules\Inventory\Models\StockMovement', 'App\Modules\Inventory\Models\StockReservation'])
    ->toOnlyBeUsedIn(['App\Modules\Inventory', 'App\Providers']); // providers only register policies

arch('product cost is not mass assignable')
    ->expect(fn () => (new ProductVariant)->isFillable('cost_price'))
    ->toBeFalse();
