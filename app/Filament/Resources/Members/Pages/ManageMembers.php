<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Members\MemberResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [InvitationResource::inviteAction()];
    }
}
