<?php

namespace App\Filament\Widgets;

use App\Models\ClinicMember;
use App\Models\Doctor;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// The clinic at a glance: staff, doctors and whether their licences are verified, and what is missing before the
// clinic can print prescriptions.
class ClinicOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $clinic = Filament::getTenant();
        $doctorIds = ClinicMember::where('clinic_id', $clinic->id)->where('role', 'DOCTOR')->pluck('user_id');
        $doctors = Doctor::whereIn('user_id', $doctorIds)->get();
        $verified = $doctors->filter->isVerified()->count();
        $missing = collect(['address' => 'address', 'phone' => 'phone', 'logo_path' => 'logo'])
            ->filter(fn ($label, $field) => blank($clinic->{$field}))->values();

        return [
            Stat::make('Staff', ClinicMember::where('clinic_id', $clinic->id)->count()),
            // the local edition doesn't verify licences (one doctor on their own PC): only the details matter there
            config('scribe.local')
                ? Stat::make('Doctors with licence details', "{$doctors->count()} of {$doctorIds->count()}")
                    ->color($doctors->count() === $doctorIds->count() && $doctorIds->isNotEmpty() ? 'success' : 'warning')
                : Stat::make('Doctors with verified licences', "{$verified} of {$doctorIds->count()}")
                    ->description($doctors->count() < $doctorIds->count() ? ($doctorIds->count() - $doctors->count()).' without licence details' : null)
                    ->color($verified === $doctorIds->count() && $doctorIds->isNotEmpty() ? 'success' : 'warning'),
            Stat::make('Clinic details', $missing->isEmpty() ? 'Complete' : 'Missing '.$missing->join(', ', ' and '))
                ->color($missing->isEmpty() ? 'success' : 'warning'),
        ];
    }
}
