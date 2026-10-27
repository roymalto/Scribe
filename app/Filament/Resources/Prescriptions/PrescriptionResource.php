<?php

namespace App\Filament\Resources\Prescriptions;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Filament\Resources\Prescriptions\Pages\EditPrescription;
use App\Filament\Resources\Prescriptions\Pages\ListPrescriptions;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\ClinicMember;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Prescribing;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Prescriptions: anyone in the clinic prepares a draft for one of its doctors; the doctor issues it (locked from then
// on), cancels it with a reason, corrects it (a new prescription that supersedes it) or repeats it. The database
// enforces all of this (migration 000600); these screens offer only what it would allow.
class PrescriptionResource extends Resource
{
    use ForRoles;

    protected static array $viewRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static array $editRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static ?string $model = Prescription::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static ?int $navigationSort = 20;

    public static function canEdit(Model $record): bool { return static::mayEdit() && $record->isDraft(); }
    public static function canDelete(Model $record): bool { return static::mayEdit() && $record->isDraft(); }
    public static function canDeleteAny(): bool { return false; }

    public static function getRecordTitle(?Model $record): string
    {
        return $record ? ($record->reference ?? 'Draft prescription').' · '.$record->patient?->fullName() : '';
    }

    // This clinic's members with the doctor role, by name
    public static function doctorOptions(): array
    {
        $ids = ClinicMember::where('clinic_id', Filament::getTenant()?->id)->where('role', 'DOCTOR')->pluck('user_id');

        return User::whereIn('id', $ids)->with('doctor')->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->doctor?->display_name ?? $u->name])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $tenant = fn () => Filament::getTenant()?->id;

        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('patient_id')->label('Patient')->required()->searchable()->live()
                    ->default(fn () => request()->integer('patient') ?: null)
                    ->getSearchResultsUsing(fn (string $search) => Patient::where('clinic_id', $tenant())
                        ->where(fn ($q) => $q->where('patient_number', 'ilike', "%{$search}%")
                            ->orWhereRaw("concat_ws(' ', first_name, middle_name, last_name) ilike ?", ["%{$search}%"])
                            ->orWhereRaw("concat_ws(' ', last_name, first_name) ilike ?", ["%{$search}%"]))
                        ->orderBy('last_name')->limit(30)->get()
                        ->mapWithKeys(fn (Patient $p) => [$p->id => $p->fullName().' · '.$p->patient_number]))
                    ->getOptionLabelUsing(fn ($value) => ($p = Patient::find($value)) ? $p->fullName().' · '.$p->patient_number : null),
                Select::make('doctor_user_id')->label('Doctor')->required()->options(fn () => static::doctorOptions())
                    ->default(fn () => auth()->user()?->roleIn(Filament::getTenant()) === 'DOCTOR' ? auth()->id() : null)
                    ->helperText('Only this doctor can issue it'),
                Callout::make('Allergies')->columnSpanFull()->warning()
                    ->description(function (Get $get) {
                        $p = Patient::find($get('patient_id'));

                        return $p?->activeAllergies()->get()
                            ->map(fn ($a) => $a->substance.($a->reaction ? " ({$a->reaction})" : ''))->implode(', ');
                    })
                    ->visible(fn (Get $get) => (bool) Patient::find($get('patient_id'))?->activeAllergies()->exists()),
            ]),
            Repeater::make('items')->label('Medicines')->relationship('items')->orderColumn('sort')
                ->columnSpanFull()->minItems(1)->defaultItems(1)->addActionLabel('Add medicine')
                ->itemLabel(fn (array $state) => trim(($state['generic_name'] ?? '').' '.($state['strength'] ?? '')) ?: null)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => $data + ['clinic_id' => $tenant()])
                ->columns(['default' => 1, 'md' => 6])
                ->schema([
                    Select::make('medication_id')->label('From the catalogue')->columnSpan(['md' => 6])->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => Medication::forClinic($tenant())->where('active', true)
                            ->where(fn ($q) => $q->where('generic_name', 'ilike', "%{$search}%")->orWhere('brand_name', 'ilike', "%{$search}%"))
                            ->orderBy('generic_name')->orderBy('strength')->limit(40)->get()
                            ->mapWithKeys(fn (Medication $m) => [$m->id => $m->label().($m->is_dangerous_drug ? ' ⚠ dangerous drug' : '')]))
                        ->getOptionLabelUsing(fn ($value) => Medication::find($value)?->label())
                        ->placeholder('Search by generic or brand name, or type the medicine below')
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $m = Medication::find($state);
                            if (! $m) {
                                return;
                            }
                            $set('generic_name', $m->generic_name);
                            $set('brand_name', $m->brand_name);
                            $set('strength', $m->strength);
                            $set('dosage_form', $m->dosage_form);
                            $set('is_dangerous_drug', $m->is_dangerous_drug);
                            $warnings = Prescribing::allergyWarnings(Patient::find($get('../../patient_id')), [$m->generic_name]);
                            if ($warnings) {
                                Notification::make()->warning()->title('Allergy')->body(implode("\n", $warnings))->persistent()->send();
                            }
                        }),
                    TextInput::make('generic_name')->label('Generic name')->required()->maxLength(255)->columnSpan(['md' => 2]),
                    TextInput::make('brand_name')->label('Brand')->maxLength(255)->columnSpan(['md' => 2]),
                    TextInput::make('strength')->maxLength(60)->columnSpan(['md' => 1]),
                    TextInput::make('dosage_form')->label('Form')->maxLength(60)->columnSpan(['md' => 1]),
                    TextInput::make('sig')->label('Directions (Sig)')->required()->maxLength(500)->columnSpan(['md' => 3])
                        ->placeholder('1 tablet 3 times a day after meals'),
                    TextInput::make('quantity')->maxLength(40)->placeholder('#21')->columnSpan(['md' => 1]),
                    TextInput::make('duration')->maxLength(60)->placeholder('7 days')->columnSpan(['md' => 1]),
                    Toggle::make('is_dangerous_drug')->label('Dangerous drug')->inline(false)->columnSpan(['md' => 1]),
                ]),
            Section::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Textarea::make('notes')->rows(2)->helperText('Printed under the medicines'),
                TextInput::make('follow_up')->label('Follow-up')->maxLength(255)->placeholder('Return in 2 weeks'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Allergy warning')->columnSpanFull()->danger()
                ->description(fn (Prescription $record) => implode(' · ', Prescribing::allergyWarnings(
                    $record->patient, $record->items->pluck('generic_name'))))
                ->visible(fn (Prescription $record) => (bool) Prescribing::allergyWarnings(
                    $record->patient, $record->items->pluck('generic_name'))),
            Callout::make('Cancelled')->columnSpanFull()->danger()
                ->description(fn (Prescription $record) => $record->cancel_reason.' · '.$record->cancelledBy?->name
                    .', '.$record->cancelled_at?->format('j M Y H:i'))
                ->visible(fn (Prescription $record) => $record->status === 'CANCELLED'),
            Section::make()->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Prescription::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => Prescription::COLORS[$state] ?? 'gray'),
                TextEntry::make('reference')->placeholder('Given when issued'),
                TextEntry::make('issued_at')->label('Issued')->dateTime('j M Y H:i')->placeholder('Not yet'),
                TextEntry::make('patient')->label('Patient')
                    ->state(fn (Prescription $record) => $record->patient->fullName().' · '.$record->patient->patient_number
                        .($record->patient->age() !== null ? ' · '.$record->patient->age().' years' : '')),
                TextEntry::make('doctor')->label('Doctor')
                    ->state(fn (Prescription $record) => $record->doctor?->doctor?->display_name ?? $record->doctor?->name),
                TextEntry::make('createdBy.name')->label('Prepared by'),
                TextEntry::make('supersedes.reference')->label('Corrects')->visible(fn (Prescription $record) => $record->supersedes_id),
                TextEntry::make('supersededBy.reference')->label('Corrected by')->visible(fn (Prescription $record) => $record->status === 'SUPERSEDED'),
                TextEntry::make('repeatOf.reference')->label('Repeat of')->visible(fn (Prescription $record) => $record->repeat_of_id),
            ]),
            RepeatableEntry::make('items')->label('Medicines')->columnSpanFull()->columns(['default' => 1, 'md' => 4])->schema([
                TextEntry::make('generic_name')->label('Medicine')->weight('bold')
                    ->state(fn ($record) => $record->label())
                    ->badge(fn ($record) => $record->is_dangerous_drug)->color(fn ($record) => $record->is_dangerous_drug ? 'danger' : null),
                TextEntry::make('sig')->label('Directions')->columnSpan(['md' => 2]),
                TextEntry::make('quantity')->state(fn ($record) => collect([$record->quantity, $record->duration])->filter()->implode(' · '))
                    ->placeholder('-'),
            ]),
            Section::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('notes')->placeholder('-'),
                TextEntry::make('follow_up')->label('Follow-up')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'doctor.doctor', 'items']))
            ->defaultSort('updated_at', 'desc')->columns([
                TextColumn::make('reference')->placeholder('Draft')->searchable(),
                TextColumn::make('patient')->label('Patient')->state(fn (Prescription $p) => $p->patient->fullName())
                    ->description(fn (Prescription $p) => $p->patient->patient_number)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn ($q) => $q
                        ->whereRaw("concat_ws(' ', first_name, middle_name, last_name, patient_number) ilike ?", ["%{$search}%"]))),
                TextColumn::make('medicines')->state(fn (Prescription $p) => $p->summary())->wrap()->limit(80),
                TextColumn::make('doctor')->state(fn (Prescription $p) => $p->doctor?->doctor?->display_name ?? $p->doctor?->name)
                    ->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Prescription::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => Prescription::COLORS[$state] ?? 'gray'),
                TextColumn::make('issued_at')->label('Issued')->dateTime('j M Y H:i')->sortable()->placeholder('-'),
            ])->filters([
                SelectFilter::make('status')->options(Prescription::STATUSES),
                SelectFilter::make('doctor_user_id')->label('Doctor')->options(fn () => static::doctorOptions()),
            ])->recordActions([ViewAction::make()->label('Open')])
            ->recordUrl(fn (Prescription $p) => static::getUrl('view', ['record' => $p]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrescriptions::route('/'),
            'create' => CreatePrescription::route('/create'),
            'view' => ViewPrescription::route('/{record}'),
            'edit' => EditPrescription::route('/{record}/edit'),
        ];
    }
}
