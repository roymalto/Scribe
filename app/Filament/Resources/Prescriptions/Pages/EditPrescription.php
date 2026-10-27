<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

// Only drafts are edited (an issued prescription is locked by the database)
class EditPrescription extends EditRecord
{
    protected static string $resource = PrescriptionResource::class;
    protected static ?string $title = 'Edit draft';

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Delete draft')];
    }

    protected function getRedirectUrl(): string
    {
        return PrescriptionResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }
}
