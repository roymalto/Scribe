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
        $bytes = $this->render($p);

        $path = sprintf('prescriptions/%d/%s-%s.pdf', $p->clinic_id, $s['reference'], now()->format('YmdHis'));
        Storage::disk('local')->put($path, $bytes);

        return PrescriptionDocument::create([
            'clinic_id' => $p->clinic_id, 'prescription_id' => $p->id, 'path' => $path,
            'sha256' => hash('sha256', $bytes), 'generated_at' => now(), 'generated_by' => auth()->id(),
        ]);
    }

    // The PDF's bytes from the snapshot; without the doctor's signature image when they will sign it by hand
    public function render(Prescription $p, bool $withSignature = true): string
    {
        if ($p->isDraft() || ! $p->snapshot) {
            throw new RuntimeException('Only an issued prescription has a PDF.');
        }
        $s = $p->snapshot;

        return Pdf::loadView('pdf.prescription', [
            's' => $s,
            'logo' => $this->image($s['clinic']['logo_path'] ?? null),
            'signature' => $withSignature ? $this->image($s['doctor']['signature_path'] ?? null) : null,
        ])->setPaper('a5')->setOption('isFontSubsettingEnabled', true)->output();   // ~1 MB of fonts otherwise
    }

    // An image on the private disk as a data URI for Dompdf (no file access from the template); null if missing
    private function image(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode(Storage::disk('local')->get($path));
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
