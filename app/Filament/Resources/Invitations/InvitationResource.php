<?php

namespace App\Filament\Resources\Invitations;

use App\Filament\Concerns\ForRoles;
use App\Filament\Resources\Invitations\Pages\ManageInvitations;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\User;
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
use Illuminate\Support\HtmlString;

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
                $link = app(Invitations::class)->invite(Filament::getTenant(), $data['email'], $data['role']);
                static::sentNotification("Invitation sent to {$data['email']}", $link);
            });
    }

    // Administrators only: accept on the invited person's behalf, creating their account first if they have none
    protected static function acceptForAction(): Action
    {
        $noAccount = fn (ClinicInvitation $i) => ! User::whereRaw('lower(email) = ?', [strtolower($i->email)])->exists();

        return Action::make('acceptFor')->label('Accept for them')->icon('heroicon-o-user-plus')->color('success')
            ->visible(fn (ClinicInvitation $i) => auth()->user()->is_admin && $i->status() === 'Waiting')
            ->modalHeading(fn (ClinicInvitation $i) => "Accept for {$i->email}")
            ->modalDescription(fn (ClinicInvitation $i) => 'Adds them to this clinic as '.(Clinic::ROLES[$i->role] ?? $i->role)
                .' without them answering the link. The invitation shows it was you.'
                .($noAccount($i) ? ' They have no Scribe account yet: enter their name and a password to give them.' : ''))
            ->schema(fn (ClinicInvitation $i) => $noAccount($i) ? [
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('password')->password()->revealable()->required()->minLength(12)
                    ->helperText('12 or more characters. Give it to them; they can change it under their profile.'),
            ] : [])
            ->modalSubmitActionLabel('Accept for them')
            ->action(function (ClinicInvitation $i, array $data) {
                app(Invitations::class)->acceptFor($i, $data['name'] ?? null, $data['password'] ?? null);
                Notification::make()->success()->title("{$i->email} added to the clinic")->send();
            });
    }

    // Without a mail server nothing reaches the invited person, so show the link to pass on (stays until closed)
    protected static function sentNotification(string $title, string $link): void
    {
        $notification = Notification::make()->success()->title($title);
        if (Invitations::mailNotDelivered()) {
            $notification->persistent()->body(new HtmlString(
                'No mail server is set up, so the email was not delivered. Send them this link (one use, '
                .Invitations::DAYS_VALID.' days):<br><code style="user-select: all; word-break: break-all">'
                .e($link).'</code>'));
        }
        $notification->send();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('inviter', 'answeredBy'))
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => Clinic::ROLES[$state] ?? $state),
                TextColumn::make('status')->badge()->state(fn (ClinicInvitation $i) => $i->status())
                    ->color(fn ($state) => match ($state) { 'Accepted' => 'success', 'Waiting' => 'warning', default => 'gray' })
                    ->description(fn (ClinicInvitation $i) => $i->acceptedOnBehalf() ? 'by '.$i->answeredBy->name.' for them' : null),
                TextColumn::make('inviter.name')->label('Invited by'),
                TextColumn::make('created_at')->label('Sent')->dateTime('j M Y H:i'),
            ])
            ->recordActions([
                Action::make('resend')->label('Send again')->icon('heroicon-o-arrow-path')
                    ->visible(fn (ClinicInvitation $i) => static::mayEdit() && in_array($i->status(), ['Waiting', 'Expired', 'Declined']))
                    ->requiresConfirmation()->modalDescription('Sends a new link; the old one stops working.')
                    ->action(function (ClinicInvitation $i) {
                        $link = app(Invitations::class)->invite(Filament::getTenant(), $i->email, $i->role);
                        static::sentNotification("Invitation sent again to {$i->email}", $link);
                    }),
                static::acceptForAction(),
                DeleteAction::make()->label('Cancel')->modalHeading('Cancel invitation')
                    ->visible(fn (ClinicInvitation $i) => static::mayEdit() && $i->status() === 'Waiting'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInvitations::route('/')];
    }
}
