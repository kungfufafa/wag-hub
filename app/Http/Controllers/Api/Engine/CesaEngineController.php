<?php

namespace App\Http\Controllers\Api\Engine;

use App\Exceptions\CesaEngineException;
use App\Http\Controllers\Controller;
use App\Models\ClientApplication;
use App\Services\CesaEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CesaEngineController extends Controller
{
    public function __construct(private readonly CesaEngineService $engine) {}

    public function health(): JsonResponse
    {
        return response()->json([
            ...$this->engine->health(),
            'uptime_ms' => defined('LARAVEL_START')
                ? (int) ((microtime(true) - (float) LARAVEL_START) * 1000)
                : 0,
            'pid' => getmypid(),
        ]);
    }

    public function startSession(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            $payload = $this->jsonBody($request);

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

    public function logout(Request $request, string $session): JsonResponse
    {
        return $this->handle(function () use ($request, $session): array {
            $payload = $this->jsonBody($request);

            return $this->engine->logout(
                $this->application($request),
                $session,
                ($payload['logout'] ?? true) !== false,
            );
        });
    }

    public function send(Request $request, string $session): JsonResponse
    {
        return $this->handle(function () use ($request, $session): array {
            $payload = $this->jsonBody($request);
            $result = $this->engine->sendText(
                $this->application($request),
                $session,
                (string) ($payload['phone'] ?? ''),
                (string) ($payload['text'] ?? ''),
                (string) ($payload['idempotency_key'] ?? ''),
            );

            return $result;
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
    private function handle(callable $callback, bool $failedAsConflict = false): JsonResponse
    {
        try {
            $payload = $callback();
        } catch (CesaEngineException $exception) {
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

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $payload = $request->json()->all();

        return is_array($payload) ? $payload : [];
    }

    private function application(Request $request): ClientApplication
    {
        $application = $request->attributes->get('client_application');

        if (! $application instanceof ClientApplication) {
            throw new CesaEngineException('Unauthenticated.', 401, 'unauthenticated');
        }

        return $application;
    }
}
