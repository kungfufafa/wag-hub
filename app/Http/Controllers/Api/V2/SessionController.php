<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\EngineControlRequest;
use App\Models\EngineOperation;
use App\Models\EngineSession;
use App\Services\Engine\EngineEventService;
use App\Services\Engine\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function __construct(private SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $rows = EngineSession::query()->where('client_application_id', $request->attributes->get('client_application')->id)
            ->with('pendingOperation')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (EngineSession $row): array => $this->sessions->snapshot($row))]);
    }

    public function store(EngineControlRequest $request): JsonResponse
    {
        $row = $this->sessions->create($request->attributes->get('client_application'), $request->validated('idempotency_key'), $request->validated());

        return response()->json(['data' => ['session' => $this->sessions->snapshot($row), 'operation' => $this->sessions->operationSnapshot($row->pendingOperation)]], 201);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $row = $this->sessions->find($request->attributes->get('client_application'), $session);

        return response()->json(['data' => $this->sessions->snapshot($this->sessions->refresh($row))]);
    }

    public function rename(EngineControlRequest $request, string $session): JsonResponse
    {
        $row = $this->sessions->find($request->attributes->get('client_application'), $session);
        DB::transaction(function () use ($row, $request): void {
            $row = EngineSession::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $row->update(['name' => $request->validated('name'), 'revision' => $row->revision + 1]);
            app(EngineEventService::class)->publishSession($row);
        });

        return response()->json(['data' => $this->sessions->snapshot($row->fresh())]);
    }

    public function lifecycle(EngineControlRequest $request, string $session): JsonResponse
    {
        $row = $this->sessions->find($request->attributes->get('client_application'), $session);
        $action = $request->isMethod('DELETE') ? 'delete' : $request->route('action');
        $operation = $this->sessions->command($row, $action, $action === 'start' ? ['mode' => 'qr'] : []);

        return response()->json(['data' => ['operation' => $this->sessions->operationSnapshot($operation), 'session' => $this->sessions->snapshot($row->fresh())]], 202);
    }

    public function pairing(EngineControlRequest $request, string $session): JsonResponse
    {
        $row = $this->sessions->find($request->attributes->get('client_application'), $session);
        $operation = $this->sessions->command($row, 'pairing-code', ['phone' => $request->validated('phone')]);

        return response()->json(['data' => ['operation' => $this->sessions->operationSnapshot($operation), 'session' => $this->sessions->snapshot($row->fresh())]], 202)
            ->header('Cache-Control', 'no-store');
    }

    public function qr(Request $request, string $session): JsonResponse
    {
        $row = $this->sessions->find($request->attributes->get('client_application'), $session);
        $challenge = $this->sessions->challenge($row);

        return response()->json(['data' => [
            'id' => $challenge['challenge_id'] ?? $challenge['id'] ?? null,
            'qr' => $challenge['qr'] ?? null, 'pairing_code' => $challenge['pairing_code'] ?? null,
            'auth_method' => $challenge['auth_method'] ?? null, 'expires_at' => $challenge['expires_at'] ?? null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function operation(Request $request, string $operation): JsonResponse
    {
        $row = EngineOperation::query()->where('client_application_id', $request->attributes->get('client_application')->id)
            ->where('uuid', $operation)->firstOrFail();

        return response()->json(['data' => $this->sessions->operationSnapshot($row)]);
    }
}
