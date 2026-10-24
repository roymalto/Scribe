<?php

namespace App\Filament\Resources\Invitations;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Invitations\Pages\ManageInvitations;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Services\Invitations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Invitations to join this clinic (clinic admins). The invited person accepts or declines through the emailed link;
// nobody is added to a clinic without agreeing.
class InvitationResource extends Resource
{
    use ForRoles;

    protected static ?string $model = ClinicInvitation::class;
    protected static ?string $tenantRelationshipName = 'invitations';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;
    protected static string|\UnitEnum|null $navigationGroup = 'Clinic';
    protected static ?int $navigationSort = 25;

    public static function canCreate(): bool
    {
        return false;                                        // made by the Invite action, which sends the email
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function inviteAction(): Action
    {
        return Action::make('invite')->label('Invite')->icon('heroicon-o-envelope')
            ->visible(fn () => static::mayEdit())
            ->schema([
                TextInput::make('email')->email()->required()->maxLength(255),
                Select::make('role')->options(Clinic::ROLES)->required(),
            ])
            ->modalDescription('They get an email with a link to accept or decline (valid '.Invitations::DAYS_VALID
                               .' days). Without a Scribe account, they create one when they accept.')
            ->modalSubmitActionLabel('Send invitation')
            ->action(function (array $data) {
                app(Invitations::class)->invite(Filament::getTenant(), $data['email'], $data['role']);
                Notification::make()->success()->title("Invitation sent to {$data['email']}")->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('inviter'))
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => Clinic::ROLES[$state] ?? $state),
                TextColumn::make('status')->badge()->state(fn (ClinicInvitation $i) => $i->status())
                    ->color(fn ($state) => match ($state) { 'Accepted' => 'success', 'Waiting' => 'warning', default => 'gray' }),
                TextColumn::make('inviter.name')->label('Invited by'),
                TextColumn::make('created_at')->label('Sent')->dateTime('j M Y H:i'),
            ])
            ->recordActions([
                Action::make('resend')->label('Send again')->icon('heroicon-o-arrow-path')
                    ->visible(fn (ClinicInvitation $i) => static::mayEdit() && in_array($i->status(), ['Waiting', 'Expired', 'Declined']))
                    ->requiresConfirmation()->modalDescription('Sends a new link; the old one stops working.')
                    ->action(function (ClinicInvitation $i) {
                        app(Invitations::class)->invite(Filament::getTenant(), $i->email, $i->role);
                        Notification::make()->success()->title("Invitation sent again to {$i->email}")->send();
                    }),
                DeleteAction::make()->label('Cancel')->modalHeading('Cancel invitation')
                    ->visible(fn (ClinicInvitation $i) => static::mayEdit() && $i->status() === 'Waiting'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInvitations::route('/')];
    }
}
