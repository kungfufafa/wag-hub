<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\ClientApplication;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, AttachmentService $attachments): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $attachment = $attachments->createFromUpload(
                $request->file('file'),
                (int) $application->getKey(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => ['code' => 'attachment_invalid', 'retryable' => false],
                'request_id' => $request->attributes->get('request_id'),
            ], 422);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Attachment gagal disimpan.',
                'error' => ['code' => 'attachment_storage_failed', 'retryable' => true],
                'request_id' => $request->attributes->get('request_id'),
            ], 500);
        }

        return response()->json([
            'data' => $this->data($attachment, $attachments),
            'request_id' => $request->attributes->get('request_id'),
        ], 201);
    }

    public function show(Request $request, string $uuid, AttachmentService $attachments): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');
        $attachment = Attachment::query()
            ->where('uuid', $uuid)
            ->where('client_application_id', $application->getKey())
            ->first();

        if ($attachment === null) {
            return response()->json([
                'message' => 'Attachment not found.',
                'error' => ['code' => 'not_found', 'retryable' => false],
                'request_id' => $request->attributes->get('request_id'),
            ], 404);
        }

        return response()->json([
            'data' => $this->data($attachment, $attachments),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    /** @return array<string, mixed> */
    private function data(Attachment $attachment, AttachmentService $attachments): array
    {
        return [
            'id' => (string) $attachment->uuid,
            'kind' => (string) $attachment->media_kind,
            'filename' => (string) $attachment->original_filename,
            'mime_type' => (string) $attachment->mime_type,
            'size' => (int) $attachment->size,
            'checksum' => (string) $attachment->checksum,
            'status' => $attachment->isAvailable() ? (string) $attachment->status : 'expired',
            'expires_at' => $attachment->expires_at?->toIso8601String(),
            'download_url' => $attachment->isAvailable() ? $attachments->temporaryUrl($attachment) : null,
        ];
    }
}
