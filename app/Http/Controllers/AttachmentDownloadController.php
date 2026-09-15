<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentDownloadController extends Controller
{
    public function __invoke(string $uuid, AttachmentService $attachments, ?string $filename = null): BinaryFileResponse|Response
    {
        $attachment = Attachment::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $path = $attachments->path($attachment);
        } catch (FileNotFoundException) {
            abort(404, 'Attachment sudah tidak tersedia.');
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addcslashes($attachment->original_filename, '"\\').'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
