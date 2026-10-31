<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'required' => TwoFactorService::required($user),
            'enabled' => TwoFactorService::enabled($user),
            'passed' => TwoFactorService::passed($request),
            'recovery_codes_left' => TwoFactorService::enabled($user) ? count((array) $user->two_factor_recovery_codes) : null,
        ]]);
    }

    public function setup(Request $request, TwoFactorService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->begin($user)]);
    }

    public function confirm(Request $request, TwoFactorService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => ['recovery_codes' => $service->confirm($user, $data['code'], $request)]]);
    }

    public function verify(Request $request, TwoFactorService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $service->verify($user, $data['code'] ?? null, $data['recovery_code'] ?? null, $request);

        return response()->json(['data' => ['passed' => true]]);
    }
}
