@php
    $channels = $this->channels();
    $account = $this->selectedAccount();
    $attachmentOpen = $this->attachmentFile !== null
        || filled($this->attachmentUrl)
        || filled($this->attachmentError);
    $threadOpen = $this->composingNew || filled($this->chatId);
    $threadHeading = $this->composingNew
        ? 'Obrolan baru'
        : ($this->chatTitle !== '' ? $this->chatTitle : 'Pilih percakapan');
    $channelsByDriver = $channels->groupBy(fn ($channel): string => (string) $channel->driver);
@endphp

<style>
    .fi-sc:has(.wa-inbox),
    .fi-grid:has(.wa-inbox) {
        padding: 0;
        gap: 0;
        max-width: none;
    }

    .fi-section:has(.wa-inbox) > .fi-section-content-ctn,
    .fi-section:has(.wa-inbox) .fi-section-content,
    .fi-section:has(.wa-inbox) .fi-sc-component {
        padding: 0;
        overflow: hidden;
    }

    .wa-inbox {
        --wa-line: var(--mky-border, var(--gray-200));
        --wa-muted: var(--gray-500);
        --wa-panel: var(--mky-surface-muted, var(--gray-50));
        --wa-radius: var(--mky-radius-surface, 0.5rem);
        box-sizing: border-box;
        display: grid;
        grid-template-columns: minmax(16rem, 21rem) minmax(0, 1fr);
        width: 100%;
        min-height: min(70vh, 44rem);
        max-height: calc(100vh - 12rem);
        overflow: hidden;
        border: 1px solid var(--wa-line);
        border-radius: var(--wa-radius);
        background: var(--mky-surface, white);
        color: inherit;
        font-size: 0.875rem;
        line-height: 1.4;
    }

    .dark .wa-inbox {
        --wa-muted: var(--gray-400);
    }

    .wa-inbox *,
    .wa-inbox *::before,
    .wa-inbox *::after {
        box-sizing: border-box;
    }

    .wa-inbox p,
    .wa-inbox h1,
    .wa-inbox h2,
    .wa-inbox h3 {
        margin: 0;
    }

    .wa-inbox button {
        appearance: none;
        font: inherit;
        color: inherit;
        box-shadow: none;
    }

    .wa-inbox [x-cloak] {
        display: none !important;
    }

    .wa-inbox-col {
        display: flex;
        min-width: 0;
        min-height: 0;
        flex-direction: column;
        background: var(--mky-surface, white);
    }

    .wa-inbox-col + .wa-inbox-col {
        border-left: 1px solid var(--wa-line);
    }

    .wa-inbox-head {
        display: grid;
        gap: 0.5rem;
        padding: 0.75rem 0.85rem;
        border-bottom: 1px solid var(--wa-line);
    }

    .wa-inbox-heading {
        font-family: var(--font-heading, inherit);
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--gray-950);
    }

    .dark .wa-inbox-heading {
        color: white;
    }

    .wa-inbox-scroll {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }

    .wa-inbox-item {
        display: flex;
        align-items: flex-start;
        gap: 0.7rem;
        width: 100%;
        margin: 0;
        border: 0;
        border-bottom: 1px solid var(--wa-line);
        border-radius: 0;
        padding: 0.7rem 0.85rem;
        background: transparent;
        text-align: left;
        cursor: pointer;
    }

    .wa-inbox-item:hover {
        background: var(--gray-50);
    }

    .dark .wa-inbox-item:hover {
        background: rgb(255 255 255 / 0.03);
    }

    .wa-inbox-item.is-active {
        background: var(--gray-100);
    }

    .dark .wa-inbox-item.is-active {
        background: color-mix(in oklab, var(--gray-800) 70%, transparent);
    }

    .wa-inbox-item:focus-visible,
    .wa-inbox-send:focus-visible,
    .wa-inbox-attach-toggle:focus-visible,
    .wa-inbox input:focus-visible,
    .wa-inbox textarea:focus-visible,
    .wa-inbox select:focus-visible {
        outline: 2px solid var(--primary-500);
        outline-offset: -2px;
    }

    .wa-inbox-avatar {
        flex: none;
        display: grid;
        place-items: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        background: var(--gray-100);
        color: var(--gray-700);
        font-size: 0.6875rem;
        font-weight: 600;
        line-height: 1;
    }

    .dark .wa-inbox-avatar {
        background: var(--gray-800);
        color: var(--gray-200);
    }

    .wa-inbox-item.is-active .wa-inbox-avatar {
        background: var(--gray-200);
    }

    .wa-inbox-item-body {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
        flex: 1;
    }

    .wa-inbox-item-title,
    .wa-inbox-thread-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        min-width: 0;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .wa-inbox-item-name,
    .wa-inbox-thread-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .wa-inbox-thread-name.is-idle {
        color: var(--wa-muted);
        font-weight: 500;
    }

    .wa-inbox-item-preview,
    .wa-inbox-item-time,
    .wa-inbox-meta {
        margin: 0;
        overflow: hidden;
        color: var(--wa-muted);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .wa-inbox-item-preview {
        font-size: 0.8125rem;
        font-weight: 400;
        line-height: 1.35;
    }

    .wa-inbox-item-time,
    .wa-inbox-meta {
        flex: none;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1.3;
    }

    .wa-inbox-thread {
        background: var(--mky-surface, white);
    }

    .wa-inbox-thread-body {
        display: flex;
        flex: 1;
        min-height: 0;
        flex-direction: column;
        gap: 0.4rem;
        overflow: auto;
        padding: 0.85rem;
    }

    .wa-bubble-row {
        display: flex;
        width: 100%;
    }

    .wa-bubble-row.is-in {
        justify-content: flex-start;
    }

    .wa-bubble-row.is-out {
        justify-content: flex-end;
    }

    .wa-bubble {
        display: flex;
        max-width: min(28rem, 78%);
        flex-direction: column;
        gap: 0.2rem;
        border-radius: var(--wa-radius);
        padding: 0.45rem 0.65rem 0.35rem;
        font-size: 0.875rem;
        line-height: 1.4;
    }

    .wa-bubble.is-in {
        background: var(--gray-50);
        border: 1px solid var(--wa-line);
    }

    .dark .wa-bubble.is-in {
        background: var(--gray-800);
        border-color: var(--gray-700);
        color: var(--gray-100);
    }

    .wa-bubble.is-out {
        background: var(--primary-600);
        color: white;
    }

    .wa-bubble-text {
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .wa-bubble-attachment {
        display: grid;
        gap: 0.35rem;
        margin-bottom: 0.25rem;
    }

    .wa-bubble-attachment img,
    .wa-bubble-attachment video {
        max-width: 18rem;
        max-height: 16rem;
        border-radius: calc(var(--wa-radius) - 2px);
        object-fit: cover;
    }

    .wa-bubble-attachment audio {
        width: min(18rem, 100%);
    }

    .wa-attachment-tools {
        display: grid;
        gap: 0.4rem;
        padding: 0.5rem 0.55rem;
        border: 1px solid var(--wa-line);
        border-radius: 0.5rem;
        background: var(--wa-panel);
    }

    .dark .wa-attachment-tools {
        background: rgb(255 255 255 / 0.03);
        border-color: var(--gray-700);
    }

    .wa-attachment-tools[hidden] {
        display: none !important;
    }

    .wa-attachment-tools-row {
        display: grid;
        grid-template-columns: 7.5rem minmax(0, 1fr);
        align-items: center;
        gap: 0.4rem;
    }

    .wa-attachment-tools-row.is-status {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .wa-attachment-tools small {
        color: var(--wa-muted);
        font-size: 0.75rem;
    }

    .wa-attachment-progress {
        width: min(18rem, 100%);
        height: 0.45rem;
        accent-color: var(--primary-600);
    }

    .wa-attachment-error {
        display: block;
        color: #b91c1c;
        font-size: 0.8rem;
    }

    .wa-attachment-clear {
        border: 0;
        background: transparent;
        color: var(--primary-700);
        cursor: pointer;
        font-size: 0.78rem;
        text-decoration: underline;
    }

    .wa-attachment-preview img,
    .wa-attachment-preview video {
        display: block;
        max-height: 10rem;
        max-width: 100%;
        border-radius: calc(var(--wa-radius) - 2px);
        object-fit: contain;
    }

    .wa-attachment-preview audio {
        width: min(22rem, 100%);
    }

    .wa-bubble-time {
        margin: 0;
        font-size: 0.6875rem;
        line-height: 1;
        opacity: 0.7;
        text-align: right;
    }

    .wa-bubble-status {
        margin: 0;
        color: currentColor;
        font-size: 0.6875rem;
        line-height: 1;
        opacity: 0.78;
        text-align: right;
    }

    .wa-inbox-composer {
        display: grid;
        gap: 0.45rem;
        padding: 0.65rem 0.75rem 0.7rem;
        border-top: 1px solid var(--wa-line);
        background: var(--mky-surface, white);
    }

    .wa-inbox-newchat {
        display: grid;
        gap: 0.4rem;
        grid-template-columns: minmax(0, 1fr);
    }

    .wa-inbox-composer-row {
        display: flex;
        gap: 0.45rem;
        align-items: flex-end;
    }

    .wa-inbox-attach-toggle {
        display: grid;
        flex: none;
        place-items: center;
        width: 2.5rem;
        height: 2.5rem;
        margin: 0;
        border: 1px solid var(--gray-300);
        border-radius: 0.5rem;
        padding: 0;
        background: var(--mky-surface, white);
        color: var(--gray-500);
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
    }

    .dark .wa-inbox-attach-toggle {
        border-color: var(--gray-700);
        background: rgb(255 255 255 / 0.05);
        color: var(--gray-400);
    }

    .wa-inbox-attach-toggle.is-on,
    .wa-inbox-attach-toggle[aria-expanded="true"] {
        background: var(--gray-100);
        border-color: var(--gray-400);
    }

    .dark .wa-inbox-attach-toggle.is-on,
    .dark .wa-inbox-attach-toggle[aria-expanded="true"] {
        background: rgb(255 255 255 / 0.1);
        border-color: var(--gray-600);
    }

    .wa-inbox input,
    .wa-inbox textarea,
    .wa-inbox select {
        width: 100%;
        min-width: 0;
        min-height: 2.5rem;
        margin: 0;
        border: 1px solid var(--gray-300);
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        background: var(--mky-surface, white);
        color: var(--gray-950);
        font: inherit;
        font-size: 0.875rem;
        line-height: 1.4;
        outline: none;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .wa-inbox input::placeholder,
    .wa-inbox textarea::placeholder {
        color: var(--gray-400);
    }

    .dark .wa-inbox input,
    .dark .wa-inbox textarea,
    .dark .wa-inbox select {
        border-color: var(--gray-700);
        background: rgb(255 255 255 / 0.05);
        color: var(--gray-100);
        box-shadow: none;
    }

    .wa-inbox input:focus,
    .wa-inbox textarea:focus,
    .wa-inbox select:focus {
        border-color: var(--primary-500);
        box-shadow: 0 0 0 1px var(--primary-500);
    }

    .wa-inbox input:disabled,
    .wa-inbox textarea:disabled,
    .wa-inbox select:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .wa-inbox-file {
        position: relative;
        display: flex;
        align-items: center;
        min-height: 2.5rem;
        overflow: hidden;
        border: 1px solid var(--gray-300);
        border-radius: 0.5rem;
        padding: 0 0.75rem;
        background: var(--mky-surface, white);
        color: var(--wa-muted);
        cursor: pointer;
        font-size: 0.8rem;
    }

    .dark .wa-inbox-file {
        border-color: var(--gray-700);
        background: rgb(255 255 255 / 0.05);
    }

    .wa-inbox-file input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        opacity: 0;
        cursor: pointer;
        border: 0;
        background: transparent;
    }

    .wa-inbox textarea {
        min-height: 2.5rem;
        max-height: 8rem;
        resize: vertical;
    }

    .wa-inbox-send {
        flex: none;
        height: 2.5rem;
        margin: 0;
        border: 0;
        border-radius: 0.5rem;
        padding: 0 1.1rem;
        background: var(--primary-600);
        color: white;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        transition: background 0.15s;
    }

    .wa-inbox-send:hover:not(:disabled) {
        background: var(--primary-500);
    }

    .wa-inbox-send:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .wa-inbox-empty {
        margin: 0;
        padding: 0.85rem;
        color: var(--wa-muted);
        font-size: 0.8125rem;
    }

    .wa-inbox-head .wa-inbox-empty,
    .wa-inbox-thread-body .wa-inbox-empty {
        padding: 0;
    }

    @media (max-width: 1023px) {
        .wa-inbox {
            grid-template-columns: 1fr;
            max-height: none;
        }

        .wa-inbox-col + .wa-inbox-col {
            border-left: 0;
            border-top: 1px solid var(--wa-line);
        }

        .wa-inbox-scroll {
            max-height: 14rem;
        }

        .wa-inbox-thread-body {
            min-height: 10rem;
        }

        .wa-attachment-tools-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="wa-inbox" @if ($account) wire:poll.8s="refreshInbox" @endif>
    <section class="wa-inbox-col" aria-label="Percakapan">
        <div class="wa-inbox-head">
            <h2 class="wa-inbox-heading">Percakapan</h2>
            @if ($channels->isEmpty())
                <p class="wa-inbox-empty">Belum ada akun wrapping.</p>
            @else
                <select wire:model.live="providerId" class="wa-inbox-provider" aria-label="Akun wrapping">
                    <option value="">Pilih akun wrapping</option>
                    @foreach ($channelsByDriver as $driver => $group)
                        <optgroup label="{{ $this->driverLabel($driver) }}">
                            @foreach ($group as $channel)
                                <option value="{{ $channel->id }}">
                                    {{ $this->accountOptionLabel($channel) }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            @endif
            @if ($account)
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari percakapan"
                    aria-label="Cari percakapan"
                >
            @endif
        </div>
        <div class="wa-inbox-scroll">
            @if ($channels->isNotEmpty() && $account !== null)
                @forelse ($this->chats as $chat)
                    <button
                        type="button"
                        wire:key="chat-{{ $chat['provider_id'] ?? 0 }}-{{ $chat['id'] }}"
                        wire:click="selectChat(@js($chat['id']), @js($chat['title']), {{ (int) ($chat['provider_id'] ?? 0) }})"
                        class="wa-inbox-item {{ $this->isActiveChat($chat) ? 'is-active' : '' }}"
                    >
                        <span class="wa-inbox-avatar" aria-hidden="true">{{ $this->chatInitials($chat['title']) }}</span>
                        <span class="wa-inbox-item-body">
                            <span class="wa-inbox-item-title">
                                <span class="wa-inbox-item-name">{{ $chat['title'] }}</span>
                                @if (filled($chat['timestamp'] ?? null))
                                    <span class="wa-inbox-item-time">{{ $chat['timestamp'] }}</span>
                                @endif
                            </span>
                            <p class="wa-inbox-item-preview">
                                {{ $chat['is_group'] ? 'Grup · ' : '' }}{{ ($chat['last_from_me'] ?? false) ? 'Anda: ' : '' }}{{ $chat['preview'] !== '' ? $chat['preview'] : $chat['id'] }}
                            </p>
                        </span>
                    </button>
                @empty
                    <p class="wa-inbox-empty">Tidak ada percakapan.</p>
                @endforelse
            @endif
        </div>
    </section>

    <section class="wa-inbox-col wa-inbox-thread" aria-label="{{ $threadHeading }}">
        <div class="wa-inbox-head">
            <p class="wa-inbox-thread-title">
                <span class="wa-inbox-thread-name{{ $threadOpen ? '' : ' is-idle' }}">{{ $threadHeading }}</span>
            </p>
            @if ($account && filled($this->chatId))
                <p class="wa-inbox-meta">{{ $account->name }}</p>
            @endif
            @if ($this->status)
                <p class="wa-inbox-meta">{{ $this->status }}</p>
            @endif
        </div>

        <div class="wa-inbox-thread-body" x-data x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
            @if ($this->messages === [] && filled($this->chatId) && ! $this->composingNew)
                <p class="wa-inbox-empty">Tidak ada pesan.</p>
            @endif

            @foreach ($this->messages as $message)
                <div class="wa-bubble-row {{ $message['from_me'] ? 'is-out' : 'is-in' }}" wire:key="msg-{{ $message['id'] }}">
                    <div class="wa-bubble {{ $message['from_me'] ? 'is-out' : 'is-in' }}">
                        @if (filled($message['attachment']['download_url'] ?? null))
                            <div class="wa-bubble-attachment">
                                @switch($message['attachment']['kind'] ?? '')
                                    @case('image')
                                        <a href="{{ $message['attachment']['download_url'] }}" target="_blank" rel="noreferrer">
                                            <img src="{{ $message['attachment']['download_url'] }}" alt="{{ $message['attachment']['filename'] ?? 'Gambar' }}">
                                        </a>
                                        @break
                                    @case('audio')
                                        <audio controls preload="metadata" src="{{ $message['attachment']['download_url'] }}"></audio>
                                        @break
                                    @case('video')
                                        <video controls preload="metadata" src="{{ $message['attachment']['download_url'] }}"></video>
                                        @break
                                    @default
                                        <a href="{{ $message['attachment']['download_url'] }}" target="_blank" rel="noreferrer">
                                            {{ $message['attachment']['filename'] ?? 'Unduh lampiran' }}
                                        </a>
                                @endswitch
                            </div>
                        @elseif (isset($message['attachment']))
                            <div class="wa-bubble-attachment">Lampiran sudah kedaluwarsa.</div>
                        @endif
                        <div class="wa-bubble-text">{{ $message['body'] !== '' ? $message['body'] : '[Tanpa teks]' }}</div>
                        @if (filled($message['timestamp']))
                            <div class="wa-bubble-time">{{ $message['timestamp'] }}</div>
                        @endif
                        @if (filled($message['delivery_status'] ?? null))
                            <div class="wa-bubble-status">
                                {{ match ($message['delivery_status']) {
                                    'provider_accepted' => 'Diterima provider',
                                    'processing' => 'Diproses',
                                    'queued' => 'Diantrikan',
                                    'outcome_unknown' => 'Hasil belum diketahui',
                                    'failed', 'dead_letter' => 'Gagal',
                                    default => $message['delivery_status'],
                                } }}
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($threadOpen)
            <form
                wire:submit="send"
                class="wa-inbox-composer"
                x-data="{ attachOpen: @json($attachmentOpen), uploading: false, progress: 0 }"
                x-on:livewire-upload-start="uploading = true; progress = 0; attachOpen = true"
                x-on:livewire-upload-finish="uploading = false; progress = 100"
                x-on:livewire-upload-error="uploading = false"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
            >
                @if ($this->composingNew)
                    <div class="wa-inbox-newchat">
                        <input type="text" wire:model="newRecipient" placeholder="Nomor tujuan, contoh 081234567890" aria-label="Nomor tujuan">
                    </div>
                @endif
                <div
                    id="wa-inbox-attachment-panel"
                    class="wa-attachment-tools"
                    data-expanded="{{ $attachmentOpen ? 'true' : 'false' }}"
                    @unless ($attachmentOpen)
                        hidden
                    @endunless
                    x-bind:hidden="!(attachOpen || @json($attachmentOpen))"
                >
                    <div class="wa-attachment-tools-row">
                        <select wire:model="attachmentKind" aria-label="Jenis lampiran">
                            <option value="image">Gambar</option>
                            <option value="document">Dokumen</option>
                            <option value="video">Video</option>
                            <option value="audio">Audio</option>
                        </select>
                        <label class="wa-inbox-file">
                            <input type="file" wire:model="attachmentFile" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.zip">
                            <span>Pilih file</span>
                        </label>
                    </div>
                    <div class="wa-attachment-tools-row is-status" x-show="uploading" x-cloak>
                        <progress
                            class="wa-attachment-progress"
                            max="100"
                            x-bind:value="progress"
                            aria-label="Progres upload lampiran"
                        ></progress>
                        <small>
                            <span wire:loading wire:target="attachmentFile">Mengunggah…</span>
                            <span x-text="`${Math.round(progress)}%`"></span>
                        </small>
                    </div>
                    <input type="url" wire:model.live="attachmentUrl" placeholder="Atau URL publik HTTP(S) lampiran">
                    @error('attachmentFile')
                        <small class="wa-attachment-error">{{ $message }}</small>
                    @enderror
                    @if ($this->attachmentError)
                        <small class="wa-attachment-error">{{ $this->attachmentError }}</small>
                    @endif
                    @if ($this->attachmentFile || filled($this->attachmentUrl))
                        <div class="wa-attachment-tools-row is-status">
                            <small>
                                @if ($this->attachmentFile)
                                    {{ $this->attachmentFile->getClientOriginalName() }} · {{ $this->attachmentSizeLabel() }} (maks. 16 MB)
                                @else
                                    URL lampiran siap dikirim
                                @endif
                            </small>
                            <button type="button" class="wa-attachment-clear" wire:click="clearAttachment">Hapus lampiran</button>
                        </div>
                    @endif
                    @if ($this->attachmentPreviewUrl())
                        <div class="wa-attachment-preview">
                            @switch($this->attachmentKind)
                                @case('image')
                                    <img src="{{ $this->attachmentPreviewUrl() }}" alt="Preview gambar">
                                    @break
                                @case('audio')
                                    <audio controls preload="metadata" src="{{ $this->attachmentPreviewUrl() }}"></audio>
                                    @break
                                @case('video')
                                    <video controls preload="metadata" src="{{ $this->attachmentPreviewUrl() }}"></video>
                                    @break
                            @endswitch
                        </div>
                    @endif
                </div>
                <div class="wa-inbox-composer-row">
                    <button
                        type="button"
                        class="wa-inbox-attach-toggle{{ $attachmentOpen ? ' is-on' : '' }}"
                        aria-label="Lampiran"
                        aria-controls="wa-inbox-attachment-panel"
                        x-bind:aria-expanded="(attachOpen || @json($attachmentOpen)).toString()"
                        x-on:click="attachOpen = !attachOpen"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </button>
                    <textarea
                        wire:model="draft"
                        rows="1"
                        maxlength="{{ $this->attachmentFile || filled($this->attachmentUrl) ? 1024 : 10000 }}"
                        placeholder="Tulis pesan..."
                    ></textarea>
                    <button type="submit" class="wa-inbox-send" wire:loading.attr="disabled" wire:target="send,attachmentFile">Kirim</button>
                </div>
            </form>
        @endif
    </section>
</div>
