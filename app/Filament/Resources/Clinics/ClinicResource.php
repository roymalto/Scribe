<?php

namespace App\Filament\Resources\Clinics;

use App\Filament\Resources\Clinics\Pages\ManageClinics;
use App\Models\Clinic;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Every clinic on the platform (administrators only). A clinic's admins keep its details and branding under
// Clinic > Clinic details; here the administrator opens or closes clinics.
class ClinicResource extends Resource
{
    protected static ?string $model = Clinic::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;
    protected static string|\UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(30)->alphaDash()->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn ($state) => strtolower($state))
                ->helperText('Short, used in addresses: /admin/{code}'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('city')->maxLength(255),
            Toggle::make('active')->default(true)->helperText('Switched off = hidden from everyone but administrators'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('code'),
            TextColumn::make('name')->searchable(),
            TextColumn::make('city'),
            TextColumn::make('members_count')->counts('members')->label('Staff'),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageClinics::route('/')];
    }
}
