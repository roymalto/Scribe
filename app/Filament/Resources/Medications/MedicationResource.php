<?php

namespace App\Filament\Resources\Medications;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Medications\Pages\ManageMedications;
use App\Models\Medication;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// The medicine catalogue prescriptions are built from: the shared list (kept by administrators) and this clinic's own
// additions (kept by its clinic admins). Everyone in the clinic reads it. Generic name first, brand optional.
class MedicationResource extends Resource
{
    use ForRoles;

    protected static array $viewRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static ?string $model = Medication::class;
    protected static bool $isScopedToTenant = false;
    protected static ?string $navigationLabel = 'Medicines';
    protected static ?string $modelLabel = 'medicine';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;
    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forClinic(Filament::getTenant()?->id);
    }

    // Shared items: administrators only. The clinic's own: its clinic admins.
    public static function canEdit(Model $record): bool
    {
        return $record->clinic_id === null ? (bool) auth()->user()?->is_admin : static::mayEdit();
    }

    public static function canDelete(Model $record): bool { return static::canEdit($record); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('generic_name')->label('Generic name')->required()->maxLength(255),
            TextInput::make('brand_name')->label('Brand name')->maxLength(255)->helperText('Optional'),
            TextInput::make('strength')->maxLength(60)->placeholder('500 mg'),
            TextInput::make('dosage_form')->label('Form')->maxLength(60)->placeholder('tablet, capsule, syrup')
                ->datalist(['tablet', 'capsule', 'syrup', 'suspension', 'drops', 'cream', 'ointment', 'inhaler', 'nebule', 'sachet']),
            TextInput::make('route')->maxLength(40)->placeholder('oral')
                ->datalist(['oral', 'topical', 'inhalation', 'nasal', 'ophthalmic', 'otic', 'rectal', 'vaginal']),
            Toggle::make('is_dangerous_drug')->label('Dangerous drug')
                ->helperText('Needs the doctor\'s S2 number to prescribe'),
            Toggle::make('active')->default(true)->helperText('Inactive medicines are not offered when prescribing'),
            Toggle::make('shared')->label('Add to the shared catalogue (all clinics)')
                ->visible(fn ($operation) => $operation === 'create' && auth()->user()?->is_admin),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('generic_name')->columns([
            TextColumn::make('generic_name')->label('Generic')->searchable()->sortable(),
            TextColumn::make('brand_name')->label('Brand')->searchable(),
            TextColumn::make('strength'),
            TextColumn::make('dosage_form')->label('Form'),
            TextColumn::make('route')->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('is_dangerous_drug')->label('Dangerous drug')->boolean()
                ->trueIcon('heroicon-o-exclamation-triangle')->trueColor('danger')->falseIcon('')->sortable(),
            TextColumn::make('clinic_id')->label('List')->badge()
                ->state(fn (Medication $m) => $m->clinic_id ? 'This clinic' : 'Shared')
                ->color(fn (Medication $m) => $m->clinic_id ? 'info' : 'gray'),
            IconColumn::make('active')->boolean()->toggleable(),
        ])->filters([
            TernaryFilter::make('mine')->label('List')->placeholder('All')
                ->trueLabel('This clinic only')->falseLabel('Shared only')
                ->queries(true: fn (Builder $q) => $q->whereNotNull('clinic_id'),
                          false: fn (Builder $q) => $q->whereNull('clinic_id')),
            TernaryFilter::make('is_dangerous_drug')->label('Dangerous drugs'),
            TernaryFilter::make('active')->default(true),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMedications::route('/')];
    }
}
