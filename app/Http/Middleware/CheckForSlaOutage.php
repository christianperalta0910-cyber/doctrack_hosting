<?php

namespace App\Http\Middleware;

use App\Services\SlaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The real, fast path for outage detection — SlaService::sweep() (run
 * every 5 minutes by the scheduler) is only a backstop for whenever
 * nobody happens to be using the system right when it recovers. This
 * middleware runs the SAME detectOutage()/compensateForOutage() pair on
 * the first authenticated request after a gap, instead of waiting for
 * the next scheduled tick — the moment anyone actually uses the
 * recovered system, that request is what notices it.
 *
 * Deliberately cheap for the overwhelmingly common case (no outage to
 * detect): SlaService::detectOutage() is one cache read + one cache
 * write, nothing more, unless it actually finds a gap — the heavier
 * compensation query only ever runs in that rare case. Skipped entirely
 * for guests (login, password reset, etc.) — there's no pending work to
 * compensate for someone who isn't authenticated yet.
 */
class CheckForSlaOutage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            app(SlaService::class)->checkForOutageRecovery();
        }

        return $next($request);
    }
}
