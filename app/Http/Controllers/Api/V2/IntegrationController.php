<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\WhatsAppEngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EngineControlRequest;
use App\Models\EngineIntegration;
use App\Models\EngineSession;
use App\Services\Engine\RunnerClient;
use App\Services\Engine\SessionService;
use App\Services\Engine\WebhookDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class IntegrationController extends Controller
{
    public function __construct(private SessionService $sessions) {}

    public function show(Request $request): JsonResponse
    {
        $application = $request->attributes->get('client_application');
        $integration = $this->sessions->integration($application);

        return response()->json(['data' => [
            'id' => $application->uuid, 'name' => $application->name,
            'scopes' => $request->attributes->get('api_credential')->abilities,
            'capabilities' => ['api_version' => 2, 'auth_methods' => ['qr', 'pairing'], 'message_types' => ['text', 'image', 'document', 'voice'],
                'media_max_bytes' => config('engine_v2.media_max_bytes'), 'image_mime_types' => ['image/jpeg', 'image/png'],
                'voice_mime_types' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/webm'],
                'events' => ['message', 'message.ack', 'session.status']],
            'capacity' => ['used' => EngineSession::query()->where('client_application_id', $application->id)->count(),
                'configured_limit' => $integration->configured_limit, 'maximum_allowed' => $integration->maximum_allowed],
            'webhook' => ['enabled' => $integration->webhook_enabled, 'url' => $integration->webhook_url,
                'last_success_at' => $integration->webhook_last_success_at?->toISOString(), 'last_error' => $integration->webhook_last_error],
        ]]);
    }

    public function update(EngineControlRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $application = $request->attributes->get('client_application');
            $integration = $this->sessions->integration($application);
            $used = EngineSession::query()->where('client_application_id', $application->id)->count();
            $limit = (int) $request->validated('configured_limit');
            if ($limit < $used || $limit > $integration->maximum_allowed) {
                throw new WhatsAppEngineException('The limit must cover existing accounts and remain within the granted ceiling.', 409, 'capacity_invalid');
            }
            $integration->update(['configured_limit' => $limit]);
        });

        return $this->show($request);
    }

    public function webhook(EngineControlRequest $request): JsonResponse
    {
        app(WebhookDestination::class)->assertAllowed($request->validated('url'));
        $integration = $this->sessions->integration($request->attributes->get('client_application'));
        $integration->update(['webhook_url' => $request->validated('url'), 'webhook_secret' => $request->validated('secret'), 'webhook_enabled' => true]);

        return response()->json(['data' => ['enabled' => true]])->header('Cache-Control', 'no-store');
    }

    public function live(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function desiredSessions(Request $request): JsonResponse
    {
        $token = (string) config('gateway.engine.baileys_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        return response()->json(['sessions' => EngineSession::withTrashed()->get()->map(fn (EngineSession $session): array => [
            'id' => $session->runner_id, 'generation' => $session->generation,
            'desired_state' => $session->trashed() ? 'STOPPED' : $session->desired_state,
        ])])->header('Cache-Control', 'no-store');
    }

    public function ready(RunnerClient $runner): JsonResponse
    {
        $checks = ['database' => false, 'runner' => false, 'control_worker' => false, 'transport_worker' => false];
        try {
            EngineIntegration::query()->exists();
            $checks['database'] = true;
            $checks['runner'] = ($runner->request('GET', '/health')['ok'] ?? false) === true;
            foreach (['control', 'transport'] as $worker) {
                $checks[$worker.'_worker'] = (int) Cache::get('engine:'.$worker.'-heartbeat', 0) > now()->subSeconds(90)->timestamp;
            }
        } catch (Throwable) {
            // Readiness must not expose infrastructure credentials or exceptions.
        }
        $ok = ! in_array(false, $checks, true);

        return response()->json(['ok' => $ok, 'checks' => $checks], $ok ? 200 : 503);
    }
}
