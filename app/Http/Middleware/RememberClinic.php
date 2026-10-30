<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Clinic pages: remember which clinic this is, so pages without one in the address (the profile) open in it. */
class RememberClinic
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($clinic = Filament::getTenant()) {
            $request->session()->put('scribe.clinic_id', $clinic->getKey());
        }

        return $next($request);
    }
}
