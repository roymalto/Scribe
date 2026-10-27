<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    public function getTitle(): string
    {
        return $this->record->fullName();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prescribe')->label('New prescription')->icon('heroicon-o-document-plus')
                ->url(fn () => PrescriptionResource::getUrl('create', ['patient' => $this->record->id])),
            DeleteAction::make(),
        ];
    }
}
