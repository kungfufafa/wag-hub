<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\WhatsAppEngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendEngineMessageRequest;
use App\Http\Requests\UploadEngineMediaRequest;
use App\Models\ClientApplication;
use App\Services\Engine\EngineEventService;
use App\Services\Engine\EngineMediaService;
use App\Services\Engine\EngineTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransportController extends Controller
{
    public function __construct(private EngineTransportService $transport, private EngineMediaService $media, private EngineEventService $events) {}

    public function send(SendEngineMessageRequest $request, string $session): Response
    {
        return $this->attempt(function () use ($request, $session): JsonResponse {
            $message = $this->transport->accept($this->application($request), $session, $request->validated(),
                (string) $request->header('Idempotency-Key', ''));

            return response()->json(['data' => $this->transport->snapshot($message)], 202);
        });
    }

    public function message(Request $request, string $message): Response
    {
        return $this->attempt(fn (): JsonResponse => response()->json(['data' => $this->transport->snapshot(
            $this->transport->owned($this->application($request), $message))]));
    }

    public function upload(UploadEngineMediaRequest $request): Response
    {
        return $this->attempt(fn (): JsonResponse => response()->json(['data' => $this->media->snapshot(
            $this->media->upload($this->application($request), $request->file('file'), $request->validated('type')))], 201));
    }

    public function download(Request $request, string $media): Response
    {
        return $this->attempt(function () use ($request, $media): Response {
            $attachment = $this->media->owned($this->application($request), $media);

            return response($this->media->bytes($attachment), 200, [
                'Content-Type' => $attachment->mimetype,
                'Content-Disposition' => 'attachment; filename="'.addcslashes($attachment->filename, '"\\').'"',
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    public function events(Request $request): Response
    {
        $input = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->attempt(fn (): JsonResponse => response()->json(['data' => $this->events->replay(
            $this->application($request), (int) ($input['after'] ?? 0), (int) ($input['limit'] ?? 100))]));
    }

    private function application(Request $request): ClientApplication
    {
        $application = $request->attributes->get('client_application');
        if (! $application instanceof ClientApplication) {
            throw new WhatsAppEngineException('Unauthenticated.', 401, 'unauthenticated');
        }

        return $application;
    }

    private function attempt(callable $callback): Response
    {
        try {
            return $callback();
        } catch (WhatsAppEngineException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode,
                'message' => $exception->getMessage(), 'retryable' => $exception->retryable]], $exception->statusCode);
        }
    }
}
