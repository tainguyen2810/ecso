<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\Inbox;
use Illuminate\Http\JsonResponse;

/** The bell in the top bar (B2): what is waiting for the signed-in member right now. */
class InboxController extends Controller
{
    public function __invoke(Inbox $inbox): JsonResponse
    {
        return response()->json(['data' => $inbox->items()]);
    }
}
