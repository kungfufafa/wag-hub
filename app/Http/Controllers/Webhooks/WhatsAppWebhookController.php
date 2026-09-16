<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Infrastructure\WhatsApp\InboxWebhookParser;
use App\Models\ProviderAccount;
use App\Services\WhatsAppInbox;
use App\Services\WhatsAppSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WhatsAppWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        InboxWebhookParser $parser,
        WhatsAppInbox $inbox,
        WhatsAppSessionManager $sessions,
    ): JsonResponse|Response {
        $account = ProviderAccount::query()
            ->where('uuid', $provider)
            ->first();

        if ($account === null) {
            return response()->json(['ok' => false], 404);
        }

        if ($request->isMethod('GET')) {
            return $this->verify($request, $account);
        }

        if (! $this->tokenMatches($request, $account)) {
            return response()->json(['ok' => false], 401);
        }

        // Session lifecycle events (self-hosted engine) update pairing state
        // rather than the message ledger.
        if ($sessions->handleWebhook($account, $request->all())) {
            return response()->json(['ok' => true, 'session' => $account->fresh()?->session_status]);
        }

        $recorded = 0;

        foreach ($parser->parse($account->driver, $request->all()) as $event) {
            $inbox->recordEvent($account, $event);
            $recorded++;
        }

        return response()->json(['ok' => true, 'recorded' => $recorded]);
    }

    private function verify(Request $request, ProviderAccount $account): JsonResponse|Response
    {
        $mode = $request->query('hub.mode') ?? $request->query('hub_mode');
        $token = $request->query('hub.verify_token') ?? $request->query('hub_verify_token');
        $challenge = $request->query('hub.challenge') ?? $request->query('hub_challenge');
        $secret = $this->webhookSecret($account);

        if ($mode === 'subscribe' && is_string($challenge) && $challenge !== '') {
            if ($secret === null || (is_string($token) && hash_equals($secret, $token))) {
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            return response()->json(['ok' => false], 403);
        }

        return response()->json(['ok' => true]);
    }

    private function tokenMatches(Request $request, ProviderAccount $account): bool
    {
        if ($account->driver === 'waba') {
            return true;
        }

        $secret = $this->webhookSecret($account);

        if ($secret === null) {
            return true;
        }

        $provided = $request->query('token')
            ?? $request->header('X-Webhook-Token')
            ?? $request->header('X-Api-Key');

        return is_string($provided) && $provided !== '' && hash_equals($secret, $provided);
    }

    private function webhookSecret(ProviderAccount $account): ?string
    {
        return $this->string(($account->configuration ?? [])['webhook_secret'] ?? null);
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
