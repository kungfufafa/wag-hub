<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Viewer only starts a session for API calls whose Referer matches a
 * "stateful" domain, which defaults to the APP_URL host. When the panel is
 * opened on another host (e.g. a Herd .test domain), the API would run
 * without the logged-in user and answer 403. Trust the host serving this
 * request too, so same-site calls always carry the session.
 */
class TrustCurrentHostForLogViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $appPort = parse_url($appUrl, PHP_URL_PORT);

        $domains = config('log-viewer.api_stateful_domains') ?? [
            'localhost',
            'localhost:3000',
            '127.0.0.1',
            '127.0.0.1:8000',
            '::1',
            $appHost.($appPort ? ':'.$appPort : ''),
        ];

        $domains[] = $request->getHttpHost();

        config(['log-viewer.api_stateful_domains' => array_values(array_unique(array_filter($domains)))]);

        return $next($request);
    }
}
