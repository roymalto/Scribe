<?php

namespace App\Filament\Auth;

use App\Http\Controllers\SetupController;
use Filament\Auth\Pages\Login as BaseLogin;

// Sign-in. A fresh local-edition install has no accounts yet, so it goes to the first-run screen instead.
class Login extends BaseLogin
{
    public function mount(): void
    {
        if (SetupController::needed()) {
            $this->redirect(route('setup'));

            return;
        }
        parent::mount();
    }
}
