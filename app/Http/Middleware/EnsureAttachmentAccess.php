<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed URLs are intentionally usable by providers without a Hub session.
 * When a browser is already authenticated, keep dashboard access behind the
 * active administrator gate as well.
 */
final class EnsureAttachmentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user->is_admin || ! $user->is_active)) {
            abort(403);
        }

        return $next($request);
    }
}
