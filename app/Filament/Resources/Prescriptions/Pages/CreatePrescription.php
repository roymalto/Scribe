<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Resources\Pages\CreateRecord;

// A new draft; the doctor issues it from its page
class CreatePrescription extends CreateRecord
{
    protected static string $resource = PrescriptionResource::class;
    protected static ?string $title = 'New prescription';

    protected function getRedirectUrl(): string
    {
        return PrescriptionResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }
}
