<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

// The clinic's own details and branding, printed on its prescriptions. Kept by the clinic's admins.
class EditClinicProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Clinic details';
    }

    public static function canView(Model $tenant): bool
    {
        return auth()->user()?->roleIn($tenant) === 'CLINIC_ADMIN';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('address')->maxLength(255),
            TextInput::make('city')->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('hours')->label('Clinic hours')->maxLength(255)->placeholder('Mon-Fri 9:00-17:00, Sat 9:00-12:00'),
            FileUpload::make('logo_path')->label('Logo')->image()->disk('local')->directory('clinic-logos')
                ->visibility('private')->maxSize(1024)->helperText('PNG or JPG, up to 1 MB; printed at the top of prescriptions'),
            TextInput::make('header_note')->maxLength(255)->helperText('A line under the clinic name, e.g. the specialty'),
            TextInput::make('footer_note')->maxLength(255)->helperText('A line at the bottom of prescriptions'),
        ]);
    }
}
