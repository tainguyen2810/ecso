<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * The signed URL is generated for this API route; the SPA forwards it unchanged.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->ulid !== $id || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Liên kết xác minh không hợp lệ.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['data' => ['email_verified' => true]]);
    }

    public function resend(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Đã gửi lại email xác minh.'], 202);
    }
}
