<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Issuing, cancelling, correcting and repeating prescriptions. The rules themselves are in the database (migration
 * 000600): this only calls them and turns a refusal into a message people can read.
 */
class Prescribing
{
    // Allergy words that cover a class of medicines (a warning, not a drug-interaction check)
    private const CLASSES = [
        'penicillin' => ['amoxicillin', 'co-amoxiclav', 'ampicillin', 'cloxacillin', 'penicillin'],
        'cephalosporin' => ['cefalexin', 'cephalexin', 'cefuroxime', 'cefixime', 'ceftriaxone', 'cefaclor'],
        'sulfa' => ['cotrimoxazole', 'sulfamethoxazole'],
        'nsaid' => ['ibuprofen', 'mefenamic acid', 'naproxen', 'diclofenac', 'celecoxib', 'etoricoxib', 'aspirin'],
        'quinolone' => ['ciprofloxacin', 'levofloxacin', 'ofloxacin'],
        'macrolide' => ['azithromycin', 'clarithromycin', 'erythromycin'],
    ];

    public function __construct(private PrescriptionPdf $pdf) {}

    /** Issue a draft (as its doctor). Returns null when issued, or why the database refused. */
    public function issue(Prescription $p): ?string
    {
        try {
            DB::transaction(fn () => $p->forceFill(['status' => 'ISSUED'])->save());
        } catch (QueryException $e) {
            $p->refresh();

            return self::reason($e);
        }
        $p->refresh();
        // the PDF is made now if it can be; a failure never touches the issued prescription (download makes it later)
        try {
            $this->pdf->generate($p);
        } catch (Throwable $e) {
            Log::warning("PDF for prescription {$p->id} not made: ".$e->getMessage());
        }

        return null;
    }

    public function cancel(Prescription $p, string $reason): ?string
    {
        try {
            DB::transaction(fn () => $p->forceFill(['status' => 'CANCELLED', 'cancel_reason' => trim($reason)])->save());
        } catch (QueryException $e) {
            $p->refresh();

            return self::reason($e);
        }

        return null;
    }

    /**
     * A new draft with the same medicines: a correction ($corrects, supersedes the original once issued) or a repeat.
     * The new draft is for $doctorId (the signed-in doctor, or the original's).
     */
    public function copyAsDraft(Prescription $from, bool $corrects, ?int $doctorId = null): Prescription
    {
        return DB::transaction(function () use ($from, $corrects, $doctorId) {
            $new = Prescription::create([
                'clinic_id' => $from->clinic_id, 'patient_id' => $from->patient_id,
                'doctor_user_id' => $doctorId ?? $from->doctor_user_id,
                'supersedes_id' => $corrects ? $from->id : null, 'repeat_of_id' => $corrects ? null : $from->id,
                'notes' => $from->notes, 'follow_up' => $corrects ? $from->follow_up : null,
            ]);
            foreach ($from->items as $i) {
                $new->items()->create($i->only(['clinic_id', 'sort', 'medication_id', 'generic_name', 'brand_name',
                    'strength', 'dosage_form', 'quantity', 'sig', 'duration', 'is_dangerous_drug']));
            }

            return $new;
        });
    }

    /**
     * Medicines that match one of the patient's active allergies: "Amoxicillin: allergy to Penicillin (severe, rash)".
     * Matches the generic name either way round, plus a few allergy classes (penicillins, NSAIDs, sulfa, ...).
     *
     * @param  iterable<string>  $generics
     * @return list<string>
     */
    public static function allergyWarnings(?Patient $patient, iterable $generics): array
    {
        if (! $patient) {
            return [];
        }
        $warnings = [];
        $allergies = $patient->activeAllergies()->get();
        foreach ($generics as $generic) {
            $g = mb_strtolower(trim((string) $generic));
            if ($g === '') {
                continue;
            }
            foreach ($allergies as $a) {
                $s = mb_strtolower(trim($a->substance));
                $class = collect(self::CLASSES)->first(fn ($members, $word) => str_contains($s, $word));
                $hit = str_contains($g, $s) || str_contains($s, $g)
                    || ($class && collect($class)->contains(fn ($m) => str_contains($g, $m)));
                if ($hit) {
                    $detail = implode(', ', array_filter([$a->severity !== 'UNKNOWN' ? strtolower($a->severity) : null, $a->reaction]));
                    $warnings[] = trim($generic).': allergy to '.$a->substance.($detail ? " ({$detail})" : '');
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    // The database's own message ("only the prescribing doctor can issue this prescription")
    public static function reason(QueryException $e): string
    {
        return preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|$)/s', $e->getMessage(), $m)
            ? ucfirst(trim($m[1])) : 'The database refused this change.';
    }
}
