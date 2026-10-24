<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\User;
use App\Services\Invitations;
use App\Support\DatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

// The emailed invitation link: see what it is for, then accept or decline (signed in as the invited email), or
// create an account and accept in one step when the email has no account yet.
class InvitationController extends Controller
{
    public function __construct(private Invitations $invitations) {}

    public function show(Request $request, string $token)
    {
        $inv = $this->invitations->lookup($token);
        abort_unless($inv, 404);
        $user = $request->user();
        $view = ['inv' => $inv, 'token' => $token, 'roleLabel' => Clinic::ROLES[$inv->role] ?? $inv->role, 'user' => $user,
                 'hasAccount' => false];

        if ($inv->status === 'open' && ! $user) {
            $view['hasAccount'] = User::whereRaw('lower(email) = ?', [strtolower($inv->email)])->exists();
            // after signing in, come back here
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.show', $view);
    }

    public function answer(Request $request, string $token)
    {
        $accept = $request->input('answer') === 'accept';
        try {
            $clinicId = $this->invitations->answer($token, $accept);
        } catch (QueryException $e) {
            return back()->withErrors(['answer' => $this->reason($e)]);
        }

        return $accept ? redirect('/admin/'.Clinic::whereKey($clinicId)->value('code'))
                       : redirect()->route('invitations.show', $token)->with('status', 'You declined the invitation.');
    }

    public function register(Request $request, string $token)
    {
        $inv = $this->invitations->lookup($token);
        abort_unless($inv && $inv->status === 'open', 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        if (User::whereRaw('lower(email) = ?', [strtolower($inv->email)])->exists()) {
            return back()->withErrors(['name' => 'This email already has an account: sign in to answer.']);
        }

        $clinicId = DB::transaction(function () use ($inv, $data, $token) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($inv->email), 'password' => $data['password']]);
            DatabaseContext::set($user->id);

            return ['user' => $user, 'clinic' => $this->invitations->answer($token, true)];
        });
        Auth::login($clinicId['user']);
        $request->session()->regenerate();

        return redirect('/admin/'.Clinic::whereKey($clinicId['clinic'])->value('code'));
    }

    private function reason(QueryException $e): string
    {
        foreach (['can no longer be used', 'is for another email address'] as $msg) {
            if (str_contains($e->getMessage(), $msg)) {
                return "This invitation {$msg}.";
            }
        }
        throw $e;
    }
}
