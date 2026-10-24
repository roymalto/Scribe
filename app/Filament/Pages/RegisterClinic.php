<?php

namespace App\Filament\Pages;

use App\Models\Clinic;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

// A new clinic (administrators only): shown when an administrator signs in before any clinic exists.
class RegisterClinic extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'New clinic';
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(30)->alphaDash()->unique(Clinic::class)
                ->dehydrateStateUsing(fn ($state) => strtolower($state))->helperText('Short, used in addresses: /admin/{code}'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('city')->maxLength(255),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return Clinic::create($data);
    }
}
