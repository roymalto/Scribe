<?php

namespace App\Http\Middleware;

use App\Support\DatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every web request: tell the database who is signed in, so row-level security and the audit trail apply to them. */
class SetDatabaseUser
{
    public function handle(Request $request, Closure $next): Response
    {
        DatabaseContext::set($request->user()?->getKey());
        try {
            return $next($request);
        } finally {
            DatabaseContext::clear();
        }
    }
}
