<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use App\Services\PrescriptionPdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

// Download an issued prescription's PDF. Row-level security finds it only for members of its clinic (others get 404).
class PrescriptionPdfController extends Controller
{
    public function __invoke(int $prescription, PrescriptionPdf $pdf): Response
    {
        $p = Prescription::find($prescription);
        abort_unless($p && $p->isIssued(), 404);
        $doc = $pdf->current($p);

        return response(Storage::disk('local')->get($doc->path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$p->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
