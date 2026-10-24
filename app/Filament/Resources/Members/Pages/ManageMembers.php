<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Models\ClinicMember;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add staff')->using(function (array $data) {
                return DB::transaction(function () use ($data) {
                    $clinic = Filament::getTenant();
                    $email = strtolower(trim($data['email']));
                    $user = User::whereRaw('lower(email) = ?', [$email])->first();
                    if (! $user) {
                        if (blank($data['name'] ?? null) || strlen((string) ($data['password'] ?? '')) < 12) {
                            throw ValidationException::withMessages(['mountedActions.0.data.name' =>
                                'No account has this email yet: give a name and a password (12+ characters) to create one.']);
                        }
                        $user = User::create(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
                    }
                    if (ClinicMember::where('clinic_id', $clinic->id)->where('user_id', $user->id)->exists()) {
                        throw ValidationException::withMessages(['mountedActions.0.data.email' => 'Already works in this clinic.']);
                    }

                    return ClinicMember::create(['clinic_id' => $clinic->id, 'user_id' => $user->id, 'role' => $data['role']]);
                });
            }),
        ];
    }
}
