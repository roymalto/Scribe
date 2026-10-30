<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use App\Services\PrescriptionPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

// Download an issued prescription's PDF, as a file (opened in the browser, phones and the home-screen app had no way
// back; Roy 2026-10-31). Row-level security finds it only for members of its clinic (others get 404).
// ?unsigned=1 gives the same prescription without the doctor's signature image, to print and sign by hand; it is
// made on the spot and not stored (the stored, hashed PDF stays the record).
class PrescriptionPdfController extends Controller
{
    public function __invoke(Request $request, int $prescription, PrescriptionPdf $pdf): Response
    {
        $p = Prescription::find($prescription);
        abort_unless($p && $p->isIssued(), 404);
        $unsigned = $request->boolean('unsigned');
        $bytes = $unsigned ? $pdf->render($p, withSignature: false) : Storage::disk('local')->get($pdf->current($p)->path);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$p->reference.($unsigned ? '-to-sign' : '').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
