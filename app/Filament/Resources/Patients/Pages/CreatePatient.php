<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    // Straight to the patient's page, to add allergies
    protected function getRedirectUrl(): string
    {
        return PatientResource::getUrl('edit', ['record' => $this->record]);
    }
}
