<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The local edition's first-run screen (a fresh install on a doctor's PC, no accounts yet): the doctor's account,
 * their clinic and their licence details in one go, so they can write prescriptions straight away. The account is
 * the install's administrator and the clinic's doctor. Gone once there is an account.
 */
class SetupController extends Controller
{
    public static function needed(): bool
    {
        return config('scribe.local') && ! User::exists();
    }

    // The clinic's short code, which starts every prescription reference (SAMPAGUITA-2026-00001): the first word of its
    // name that has 3 or more letters, at most 12 characters
    public static function code(string $clinicName): string
    {
        $word = collect(explode('-', Str::slug($clinicName)))->first(fn ($w) => strlen($w) >= 3);

        return $word ? substr($word, 0, 12) : 'clinic';
    }

    public function show()
    {
        abort_unless(static::needed(), 404);

        return view('setup');
    }

    public function store(Request $request)
    {
        abort_unless(static::needed(), 404);
        $data = $request->validate([
            'clinic_name' => ['required', 'string', 'max:255'],
            'clinic_address' => ['nullable', 'string', 'max:255'],
            'clinic_phone' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'prc_license_no' => ['required', 'string', 'max:20'],
            'prc_valid_until' => ['nullable', 'date'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        // The database makes it all and refuses once anyone has an account (migration 001000)
        $userId = (int) DB::selectOne('SELECT scribe.first_run_setup(?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS id', [
            $data['clinic_name'], static::code($data['clinic_name']),
            $data['clinic_address'] ?? null, $data['clinic_phone'] ?? null, $data['name'], $data['specialty'] ?? null,
            $data['prc_license_no'], $data['prc_valid_until'] ?? null, $data['email'], Hash::make($data['password']),
        ])->id;

        Auth::loginUsingId($userId);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
