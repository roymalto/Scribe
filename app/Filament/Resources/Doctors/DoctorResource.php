<?php

namespace App\Filament\Resources\Doctors;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Doctors\Pages\ManageDoctors;
use App\Models\ClinicMember;
use App\Models\Doctor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// The doctors working in this clinic and their licence details, as printed on prescriptions. Doctors keep their own;
// clinic admins can enter them; only an administrator marks a PRC licence verified (checked on the PRC site), and
// changing the licence number clears that (database trigger).
class DoctorResource extends Resource
{
    use ForRoles;

    protected static array $viewRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static ?string $model = Doctor::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;
    protected static string|\UnitEnum|null $navigationGroup = 'Clinic';
    protected static ?int $navigationSort = 30;

    // This clinic's members with the doctor role
    protected static function doctorIds(): array
    {
        return ClinicMember::where('clinic_id', Filament::getTenant()?->id)->where('role', 'DOCTOR')->pluck('user_id')->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('user_id', static::doctorIds());
    }

    public static function canCreate(): bool
    {
        $me = auth()->user();

        return static::mayEdit() || (static::role() === 'DOCTOR' && ! $me->doctor()->exists());
    }

    public static function canEdit(Model $record): bool
    {
        return static::mayEdit() || $record->user_id === auth()->id();
    }

    public static function canDelete(Model $record): bool { return (bool) auth()->user()?->is_admin; }
    public static function canDeleteAny(): bool { return (bool) auth()->user()?->is_admin; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Doctor')->required()->visibleOn('create')
                ->options(function () {
                    $ids = static::mayEdit() ? static::doctorIds() : [auth()->id()];
                    $taken = Doctor::whereIn('user_id', $ids)->pluck('user_id')->all();

                    return \App\Models\User::whereIn('id', array_diff($ids, $taken))->orderBy('name')->pluck('name', 'id');
                })
                ->default(fn () => static::mayEdit() ? null : auth()->id())
                ->helperText('People with the Doctor role under Staff who have no details yet'),
            TextInput::make('display_name')->label('Name as printed')->required()->maxLength(255)
                ->placeholder('Juan Dela Cruz, MD, FPCP'),
            TextInput::make('specialty')->maxLength(255),
            TextInput::make('prc_license_no')->label('PRC licence number')->required()->maxLength(20)
                ->helperText('Changing it clears the verification until an administrator checks it again.'),
            DatePicker::make('prc_valid_until')->label('PRC licence valid until'),
            TextInput::make('ptr_no')->label('PTR number')->maxLength(30),
            TextInput::make('s2_no')->label('S2 number')->maxLength(30)->helperText('PDEA licence, for dangerous drugs'),
            FileUpload::make('signature_path')->label('Signature')->image()->acceptedFileTypes(['image/png', 'image/jpeg'])
                ->disk('local')->directory('doctor-signatures/'.auth()->id())->visibility('private')->maxSize(512)
                ->preventFilePathTampering()
                ->visible(fn (?Doctor $record) => static::isOwn($record))
                ->helperText('Printed above your name on prescriptions. Sign in black ink on white paper, photograph or scan '
                             .'it and crop close; PNG or JPG, up to 512 KB. Only you can set it; prescriptions already '
                             .'issued keep the signature they were issued with.'),
        ]);
    }

    // The signed-in person's own doctor record (or the one they're creating for themselves)
    protected static function isOwn(?Doctor $record): bool
    {
        return $record ? $record->user_id === auth()->id() : ! static::mayEdit() && static::role() === 'DOCTOR';
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('verifiedBy'))->defaultSort('display_name')->columns([
            TextColumn::make('display_name')->label('Name')->searchable(),
            TextColumn::make('specialty'),
            TextColumn::make('prc_license_no')->label('PRC no.'),
            TextColumn::make('prc_valid_until')->label('Valid until')->date('j M Y')
                ->color(fn (Doctor $d) => $d->licenseExpired() ? 'danger' : null),
            IconColumn::make('signed')->label('Signature')->boolean()->state(fn (Doctor $d) => filled($d->signature_path)),
            IconColumn::make('verified')->label('Verified')->boolean()->state(fn (Doctor $d) => $d->isVerified())
                ->tooltip(fn (Doctor $d) => $d->isVerified()
                    ? 'By '.($d->verifiedBy?->name ?? '?').' on '.$d->license_verified_at->timezone(config('app.timezone'))->format('j M Y')
                    : 'Not verified yet'),
        ])->recordActions([
            EditAction::make()->visible(fn (Doctor $d) => static::canEdit($d)),   // doesn't hide itself

            Action::make('verify')->label('Verify')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Doctor $d) => auth()->user()->is_admin && ! $d->isVerified())
                ->requiresConfirmation()
                ->modalDescription(fn (Doctor $d) => "Check licence {$d->prc_license_no} on the PRC verification site first: "
                                                     .config('scribe.prc_verification_url'))
                ->action(function (Doctor $d) {
                    $d->forceFill(['license_verified_at' => now()])->save();
                    Notification::make()->success()->title('Licence marked verified')->send();
                }),
            Action::make('unverify')->label('Clear verification')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Doctor $d) => auth()->user()->is_admin && $d->isVerified())
                ->requiresConfirmation()
                ->action(fn (Doctor $d) => $d->forceFill(['license_verified_at' => null])->save()),
            Action::make('removeSignature')->label('Remove signature')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (Doctor $d) => auth()->user()->is_admin && $d->signature_path && $d->user_id !== auth()->id())
                ->requiresConfirmation()
                ->modalDescription('New prescriptions print without a signature until the doctor uploads one. '
                                   .'Prescriptions already issued keep theirs.')
                ->action(fn (Doctor $d) => $d->forceFill(['signature_path' => null])->save()),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDoctors::route('/')];
    }
}
