<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Models\PatientAllergy;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// A patient's allergies. Prescribing warns when a medicine's generic name matches an active one.
class AllergiesRelationManager extends RelationManager
{
    protected static string $relationship = 'allergies';
    protected static ?string $title = 'Allergies';

    protected function canDelete(Model $record): bool
    {
        return auth()->user()?->roleIn($this->getOwnerRecord()->clinic_id) === 'CLINIC_ADMIN';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('substance')->required()->maxLength(255)
                ->helperText('A medicine (generic name, e.g. Amoxicillin), a class (Penicillins) or other substance'),
            TextInput::make('reaction')->maxLength(255)->placeholder('Rash, hives, swelling'),
            Select::make('severity')->options(PatientAllergy::SEVERITIES)->default('UNKNOWN')->required(),
            Select::make('status')->options(PatientAllergy::STATUSES)->default('ACTIVE')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('substance')->defaultSort('substance')->columns([
            TextColumn::make('substance'),
            TextColumn::make('reaction'),
            TextColumn::make('severity')->badge()->formatStateUsing(fn ($state) => PatientAllergy::SEVERITIES[$state] ?? $state)
                ->color(fn ($state) => match ($state) { 'SEVERE' => 'danger', 'MODERATE' => 'warning', default => 'gray' }),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => PatientAllergy::STATUSES[$state] ?? $state)
                ->color(fn ($state) => $state === 'ACTIVE' ? 'danger' : 'gray'),
        ])->headerActions([
            // the patient's clinic, so the composite foreign key and row-level security agree
            CreateAction::make()->label('Add allergy')
                ->mutateDataUsing(fn (array $data) => $data + ['clinic_id' => $this->getOwnerRecord()->clinic_id]),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
