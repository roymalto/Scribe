<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Sign-in accounts (administrators only). Clinic admins can also add people under Clinic > Staff.
class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static string|\UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')->password()->revealable()->minLength(12)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('12 or more characters. On edit, leave empty to keep the current one.'),
            Toggle::make('is_admin')->label('Administrator (every clinic, accounts, licence verification; must use two-factor sign-in)'),
            Toggle::make('active')->default(true)->helperText('Switched off = can no longer sign in'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('clinics.name')->label('Clinics')->badge()->separator(','),
            IconColumn::make('is_admin')->label('Admin')->boolean(),
            IconColumn::make('two_factor')->label('2FA')->boolean()->state(fn (User $u) => filled($u->app_authentication_secret)),
            IconColumn::make('active')->boolean(),
        ])->recordActions([
            EditAction::make(),
            Action::make('reset2fa')->label('Reset two-factor')->icon('heroicon-o-key')->color('danger')
                ->visible(fn (User $u) => filled($u->app_authentication_secret))
                ->requiresConfirmation()->modalDescription('For a lost phone: they set up two-factor sign-in again at their next sign-in.')
                ->action(function (User $u) {
                    $u->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                    Notification::make()->success()->title('Two-factor sign-in reset')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
