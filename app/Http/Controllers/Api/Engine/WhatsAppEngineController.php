<?php

namespace App\Http\Controllers\Api\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EngineRequest;
use App\Models\ClientApplication;
use App\Services\WhatsAppEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WhatsAppEngineController extends Controller
{
    public function __construct(protected readonly WhatsAppEngineService $engine) {}

    public function health(Request $request): JsonResponse
    {
        $health = $this->engine->health($this->application($request));

        return response()->json([
            ...$health,
            'api_version' => 'v1',
            'capabilities' => ['sessions:qr', 'sessions:pairing', 'messages:text', 'messages:status'],
            'uptime_ms' => defined('LARAVEL_START')
                ? (int) ((microtime(true) - (float) LARAVEL_START) * 1000)
                : 0,
            'pid' => getmypid(),
        ], $health['ok'] ? 200 : 503);
    }

    public function startSession(EngineRequest $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            $payload = $request->validated();

            return $this->engine->startSession(
                $this->application($request),
                (string) ($payload['id'] ?? ''),
                (string) ($payload['mode'] ?? ''),
                isset($payload['phone']) ? (string) $payload['phone'] : null,
            );
        });
    }

    public function session(Request $request, string $session): JsonResponse
    {
        return $this->handle(fn (): array => $this->engine->session($this->application($request), $session));
    }

    public function logout(EngineRequest $request, string $session): JsonResponse
    {
        return $this->handle(function () use ($request, $session): array {
            $payload = $request->validated();

            return $this->engine->logout(
                $this->application($request),
                $session,
                ($payload['logout'] ?? true) !== false,
            );
        });
    }

    public function send(EngineRequest $request, string $session): JsonResponse
    {
        return $this->handle(function () use ($request, $session): array {
            $payload = $request->validated();

            return $this->engine->sendText(
                $this->application($request),
                $session,
                (string) ($payload['phone'] ?? ''),
                (string) ($payload['text'] ?? ''),
                (string) ($payload['idempotency_key'] ?? ''),
            );
        }, failedAsConflict: true);
    }

    public function message(Request $request, string $session, string $key): JsonResponse
    {
        return $this->handle(
            fn (): array => $this->engine->messageStatus($this->application($request), $session, $key),
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    protected function handle(callable $callback, bool $failedAsConflict = false): JsonResponse
    {
        try {
            $payload = $callback();
        } catch (WhatsAppEngineException $exception) {
            return response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'error_code' => $exception->errorCode,
            ], $exception->statusCode);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => 'Engine WhatsApp gagal memproses permintaan.',
                'retryable' => true,
                'error_code' => 'engine_error',
            ], 500);
        }

        $status = 200;

        if ($failedAsConflict && ($payload['status'] ?? null) === 'failed') {
            $status = 409;
        }

        return response()->json($payload, $status);
    }

    protected function application(Request $request): ClientApplication
    {
        $application = $request->attributes->get('client_application');

        if (! $application instanceof ClientApplication) {
            throw new WhatsAppEngineException('Unauthenticated.', 401, 'unauthenticated');
        }

        return $application;
    }
}
