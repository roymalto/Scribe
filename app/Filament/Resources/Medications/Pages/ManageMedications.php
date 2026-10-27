<?php

namespace App\Filament\Resources\Medications\Pages;

use App\Filament\Resources\Medications\MedicationResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageMedications extends ManageRecords
{
    protected static string $resource = MedicationResource::class;

    protected function getHeaderActions(): array
    {
        // a clinic admin's additions belong to this clinic; an administrator may add to the shared list instead
        return [CreateAction::make()->label('Add medicine')->mutateDataUsing(function (array $data) {
            $shared = auth()->user()->is_admin && ($data['shared'] ?? false);
            unset($data['shared']);

            return $data + ['clinic_id' => $shared ? null : Filament::getTenant()?->id];
        })];
    }
}
