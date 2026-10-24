<?php

namespace App\Filament\Resources\Members;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Members\Pages\ManageMembers;
use App\Models\Clinic;
use App\Models\ClinicMember;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Who works in this clinic, and as what. People join only by accepting an invitation (Invite on this page; see
// Invitations); clinic admins change roles and remove people here.
class MemberResource extends Resource
{
    use ForRoles;

    protected static array $viewRoles = ['CLINIC_ADMIN', 'DOCTOR', 'STAFF'];
    protected static ?string $model = ClinicMember::class;
    protected static ?string $tenantRelationshipName = 'members';
    protected static ?string $navigationLabel = 'Staff';
    protected static ?string $modelLabel = 'staff member';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static string|\UnitEnum|null $navigationGroup = 'Clinic';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')->options(Clinic::ROLES)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('user.doctor'))->columns([
            TextColumn::make('user.name')->label('Name')->searchable(),
            TextColumn::make('user.email')->label('Email'),
            TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => Clinic::ROLES[$state] ?? $state),
            IconColumn::make('verified')->label('PRC verified')->boolean()
                ->state(fn (ClinicMember $m) => $m->role === 'DOCTOR' ? (bool) $m->user?->doctor?->isVerified() : null),
            IconColumn::make('user.active')->label('Active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()->label('Remove')]);
    }

    public static function canCreate(): bool
    {
        return false;                                    // people join by invitation
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembers::route('/')];
    }
}
