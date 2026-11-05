<?php

namespace App\Modules\Geo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Geo\Services\VnAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vietnam's provinces and wards (units since 1 July 2025) for the address pickers, and reading of a pasted address.
 * The lists change only with a new data migration, so browsers may cache them for a day.
 */
class GeoController extends Controller
{
    public function provinces(): JsonResponse
    {
        $data = Province::query()->orderBy('code')->get()->map(fn (Province $p) => [
            'code' => $p->code,
            'name' => $p->name,
            'short_name' => $p->short_name,
            'former' => $p->former ?? [],
        ]);

        return response()->json(['data' => $data])->setPublic()->setMaxAge(86400);
    }

    public function wards(string $province): JsonResponse
    {
        abort_unless(Province::query()->whereKey($province)->exists(), 404);
        $data = Ward::query()->where('province_code', $province)->orderBy('name')->get()->map(fn (Ward $w) => [
            'code' => $w->code,
            'name' => $w->name,
            'former_hint' => $w->former_hint,
            'search' => $w->search,
        ]);

        return response()->json(['data' => $data])->setPublic()->setMaxAge(86400);
    }

    public function suggest(Request $request, VnAddress $addresses): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'max:500']])['q'];

        return response()->json(['data' => $addresses->suggest($q)]);
    }
}
