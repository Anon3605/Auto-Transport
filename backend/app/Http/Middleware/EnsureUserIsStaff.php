<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin panel's web routes. Runs behind 'auth' -- a guest is the
 * auth middleware's problem, since it knows how to redirect to the login form.
 * This answers one question only: is this authenticated person staff.
 *
 * 403 rather than 404: the admin URLs are not the secret, the data behind them
 * is, and a customer who mistypes /admin deserves a comprehensible answer.
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // isStaff() resolves assigned role names through UserRole::tryFrom(), so a
        // role row hand-inserted into the DB that matches no enum case grants
        // nothing -- staffness is defined in code, not by whatever is in `roles`.
        //
        // AuthorizationException rather than abort(403): the framework turns it
        // into a 403 for the admin panel's HTML pages, and bootstrap/app.php
        // renders it as { message, errors } for anything expecting JSON. One throw,
        // both surfaces, same shape as a policy denial.
        if ($user === null || ! $user->isStaff()) {
            throw new AuthorizationException('Staff access required.');
        }

        return $next($request);
    }
}
