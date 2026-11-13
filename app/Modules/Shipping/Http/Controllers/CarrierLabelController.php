<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** A carrier label kept as a PDF (J&T answers with the file, not a link), opened through a signed one-hour link. */
class CarrierLabelController extends Controller
{
    public function __invoke(string $file): Response
    {
        $path = 'carrier-labels/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="nhan-van-don.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
