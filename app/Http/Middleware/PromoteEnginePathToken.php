<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets cesa-web keep using WhatsAppEngineClient without a Bearer header:
 * set REKRUTMEN_WHATSAPP_ENGINE_URL=https://hub.example/engine/t/{token}.
 */
class PromoteEnginePathToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('engineToken');

        if (is_string($token) && trim($token) !== '' && $request->bearerToken() === null) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
