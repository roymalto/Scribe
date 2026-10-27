<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\RelationManagers\AllergiesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PrescriptionsRelationManager;
use App\Models\Patient;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// The clinic's patients: everyone in the clinic registers and updates them; only clinic admins delete. Allergies and
// medication history are on each patient's page. Row-level security keeps each clinic's patients to itself.
class PatientResource extends Resource
{
    use ForRoles;

    protected static array $viewRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static array $editRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static ?string $model = Patient::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $recordTitleAttribute = 'last_name';
    protected static ?int $navigationSort = 10;

    public static function canDelete(Model $record): bool { return static::role() === 'CLINIC_ADMIN'; }
    public static function canDeleteAny(): bool { return static::role() === 'CLINIC_ADMIN'; }

    public static function getRecordTitle(?Model $record): string
    {
        return $record ? $record->fullName().' ('.$record->patient_number.')' : '';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextInput::make('last_name')->required()->maxLength(255),
                TextInput::make('first_name')->required()->maxLength(255),
                TextInput::make('middle_name')->maxLength(255),
                DatePicker::make('birth_date')->label('Date of birth')->maxDate(now())->native(false)
                    ->displayFormat('j M Y'),
                Select::make('sex')->options(Patient::SEXES),
                TextInput::make('patient_number')->label('Patient number')->maxLength(20)
                    ->placeholder('Given automatically')->helperText('Leave empty for the next number'),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('address')->maxLength(255),
            ]),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->withCount('activeAllergies'))
            ->defaultSort('last_name')->columns([
                TextColumn::make('patient_number')->label('No.')->searchable()->sortable(),
                TextColumn::make('name')->label('Name')->state(fn (Patient $p) => $p->fullName())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q
                        ->whereRaw("concat_ws(' ', first_name, middle_name, last_name) ilike ?", ["%{$search}%"])
                        ->orWhereRaw("concat_ws(' ', last_name, first_name) ilike ?", ["%{$search}%"])))
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('birth_date')->label('Born')->date('j M Y')
                    ->description(fn (Patient $p) => $p->age() !== null ? $p->age().' years' : null),
                TextColumn::make('sex')->formatStateUsing(fn ($state) => Patient::SEXES[$state] ?? $state),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('active_allergies_count')->label('Allergies')->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
            ])->filters([
                SelectFilter::make('sex')->options(Patient::SEXES),
            ])->recordActions([EditAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [AllergiesRelationManager::class, PrescriptionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'create' => CreatePatient::route('/create'),
            'edit' => EditPatient::route('/{record}/edit'),
        ];
    }
}
