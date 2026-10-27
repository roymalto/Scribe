<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\PrescriptionDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The PDF of an issued prescription, made from its snapshot (what was true when it was issued, not today's details),
 * kept on the private disk with its SHA-256 and recorded in prescription_documents. There is no public link: only
 * clinic members download it (PrescriptionPdfController).
 */
class PrescriptionPdf
{
    public function generate(Prescription $p): PrescriptionDocument
    {
        if ($p->isDraft() || ! $p->snapshot) {
            throw new RuntimeException('Only an issued prescription has a PDF.');
        }
        $s = $p->snapshot;
        $logo = null;
        $logoPath = $s['clinic']['logo_path'] ?? null;
        if ($logoPath && Storage::disk('local')->exists($logoPath)) {
            $mime = Storage::disk('local')->mimeType($logoPath) ?: 'image/png';
            $logo = "data:{$mime};base64,".base64_encode(Storage::disk('local')->get($logoPath));
        }
        $bytes = Pdf::loadView('pdf.prescription', ['s' => $s, 'logo' => $logo])->setPaper('a5')->output();

        $path = sprintf('prescriptions/%d/%s-%s.pdf', $p->clinic_id, $s['reference'], now()->format('YmdHis'));
        Storage::disk('local')->put($path, $bytes);

        return PrescriptionDocument::create([
            'clinic_id' => $p->clinic_id, 'prescription_id' => $p->id, 'path' => $path,
            'sha256' => hash('sha256', $bytes), 'generated_at' => now(), 'generated_by' => auth()->id(),
        ]);
    }

    /** The latest PDF that still matches its recorded hash, made now if there is none. */
    public function current(Prescription $p): PrescriptionDocument
    {
        $doc = $p->documents()->first();
        if ($doc && Storage::disk('local')->exists($doc->path)) {
            if (hash('sha256', Storage::disk('local')->get($doc->path)) !== $doc->sha256) {
                throw new RuntimeException("The stored PDF for {$p->reference} does not match its recorded hash.");
            }

            return $doc;
        }

        return $this->generate($p);
    }
}
