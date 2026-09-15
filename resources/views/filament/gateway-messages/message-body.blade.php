@php
    /** @var \App\Domain\Delivery\OutboundAttachment|null $attachment */
    $body = (string) ($body ?? '');
    $attachment = $attachment ?? null;
    $kindLabel = $attachment ? match ($attachment->kind) {
        \App\Domain\Delivery\AttachmentKind::Image => 'Gambar',
        \App\Domain\Delivery\AttachmentKind::Document => 'Dokumen',
        \App\Domain\Delivery\AttachmentKind::Video => 'Video',
        \App\Domain\Delivery\AttachmentKind::Audio => 'Audio',
    } : null;
    $attachmentUrl = $attachment?->url;
    $attachmentExpired = false;

    if ($attachment?->attachmentId !== null) {
        $storedAttachment = \App\Models\Attachment::query()
            ->where('uuid', $attachment->attachmentId)
            ->first();

        if ($storedAttachment?->isAvailable()) {
            $attachmentUrl = app(\App\Services\AttachmentService::class)->temporaryUrl($storedAttachment);
        } else {
            $attachmentUrl = null;
            $attachmentExpired = true;
        }
    }
@endphp

<div class="space-y-3">
    @if ($attachment !== null)
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                    Lampiran: {{ $kindLabel }}
                </span>
                <span class="font-medium text-gray-950 dark:text-white">{{ $attachment->resolvedFilename() }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $attachment->resolvedMimeType() }}</span>
            </div>
            @if ($attachmentUrl !== null)
                <a
                    href="{{ $attachmentUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-2 block break-all text-xs text-primary-600 hover:underline dark:text-primary-400"
                >{{ $attachmentUrl }}</a>
            @elseif ($attachmentExpired)
                <span class="mt-2 block text-xs text-gray-500 dark:text-gray-400">File attachment sudah kedaluwarsa.</span>
            @endif
        </div>
    @endif

    <textarea
        readonly
        data-message-body
        rows="8"
        class="block w-full rounded-lg border border-gray-300 bg-white p-3 font-sans text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
        placeholder="{{ $attachment !== null ? 'Lampiran dikirim tanpa caption.' : '' }}"
    >{{ $body }}</textarea>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Kalau tombol salin tidak menempel, blok teks di kotak ini lalu tekan Ctrl+C atau Cmd+C.
    </p>
</div>
