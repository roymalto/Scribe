<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// The patient's medication history: every prescription, newest first; open one to repeat or correct it
class PrescriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'prescriptions';
    protected static ?string $title = 'Medication history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['items', 'doctor.doctor']))
            ->defaultSort('updated_at', 'desc')->columns([
                TextColumn::make('issued_at')->label('Date')->date('j M Y')->placeholder('Draft'),
                TextColumn::make('medicines')->state(fn (Prescription $p) => $p->items
                    ->map(fn ($i) => $i->label().($i->sig ? ' — '.$i->sig : ''))->implode("\n"))->wrap()
                    ->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                TextColumn::make('doctor')->state(fn (Prescription $p) => $p->doctor?->doctor?->display_name ?? $p->doctor?->name),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Prescription::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => Prescription::COLORS[$state] ?? 'gray'),
                TextColumn::make('reference')->placeholder('-'),
            ])->recordActions([
                Action::make('open')->label('Open')->url(fn (Prescription $p) => PrescriptionResource::getUrl('view', ['record' => $p])),
            ])->recordUrl(fn (Prescription $p) => PrescriptionResource::getUrl('view', ['record' => $p]));
    }
}
