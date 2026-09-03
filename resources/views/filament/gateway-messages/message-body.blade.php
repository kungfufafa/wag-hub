@php
    $body = (string) ($body ?? '');
@endphp

<div class="space-y-3">
    <textarea
        readonly
        data-message-body
        rows="8"
        class="block w-full rounded-lg border border-gray-300 bg-white p-3 font-sans text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
    >{{ $body }}</textarea>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Kalau tombol salin tidak menempel, blok teks di kotak ini lalu tekan Ctrl+C atau Cmd+C.
    </p>
</div>
