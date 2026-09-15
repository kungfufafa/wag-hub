@php
    $channels = $this->channels();
    $account = $this->selectedAccount();
    $attachmentOpen = $this->attachmentFile !== null
        || filled($this->attachmentUrl)
        || filled($this->attachmentError);
    $threadHeading = $this->composingNew
        ? 'Obrolan baru'
        : ($this->chatTitle !== '' ? $this->chatTitle : 'Pilih percakapan');
@endphp

<style>
    .fi-sc:has(.wa-inbox),
    .fi-section:has(.wa-inbox),
    .fi-grid:has(.wa-inbox) {
        padding: 0;
        gap: 0;
        max-width: none;
    }

    .wa-inbox {
        --wa-line: color-mix(in oklab, var(--gray-400) 28%, transparent);
        --wa-muted: var(--gray-500);
        --wa-panel: var(--gray-50);
        box-sizing: border-box;
        display: grid;
        grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
        width: 100%;
        min-height: min(72vh, 46rem);
        max-height: calc(100vh - 11.5rem);
        overflow: hidden;
        border: 1px solid var(--wa-line);
        border-radius: 1rem;
        background: var(--gray-50);
        color: inherit;
        font-size: 0.9rem;
        line-height: 1.4;
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

    .dark .wa-inbox {
        --wa-line: color-mix(in oklab, var(--gray-400) 22%, transparent);
        --wa-panel: color-mix(in oklab, var(--gray-950) 55%, transparent);
        background: color-mix(in oklab, var(--gray-950) 35%, transparent);
    }

    .wa-inbox-col {
        display: flex;
        min-width: 0;
        min-height: 0;
        flex-direction: column;
        background: white;
    }

    .dark .wa-inbox-col {
        background: color-mix(in oklab, var(--gray-900) 88%, transparent);
    }

    .wa-inbox-col + .wa-inbox-col {
        border-left: 1px solid var(--wa-line);
    }

    .wa-inbox-head {
        display: grid;
        gap: 0.45rem;
        padding: 0.7rem 0.85rem 0.65rem;
        border-bottom: 1px solid var(--wa-line);
    }

    .wa-inbox-kicker {
        margin: 0;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--wa-muted);
    }

    .wa-inbox-scroll {
        flex: 1;
        min-height: 0;
        overflow: auto;
        padding: 0.3rem;
    }

    .wa-inbox-item {
        display: grid;
        gap: 0.12rem;
        width: 100%;
        margin: 0;
        border: 0;
        border-radius: 0.7rem;
        padding: 0.5rem 0.65rem;
        background: transparent;
        text-align: left;
        cursor: pointer;
    }

    .wa-inbox-item:hover {
        background: var(--wa-panel);
    }

    .wa-inbox-item.is-active {
        background: color-mix(in oklab, var(--primary-500) 12%, transparent);
    }

    .wa-inbox-item-title,
    .wa-inbox-thread-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        min-width: 0;
        font-size: 0.9rem;
        font-weight: 650;
        line-height: 1.25;
    }

    .wa-inbox-item-name,
    .wa-inbox-thread-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .wa-inbox-item-preview,
    .wa-inbox-item-foot,
    .wa-inbox-meta {
        margin: 0;
        overflow: hidden;
        color: var(--wa-muted);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .wa-inbox-item-preview {
        font-size: 0.78rem;
        line-height: 1.3;
    }

    .wa-inbox-item-foot,
    .wa-inbox-meta {
        font-size: 0.68rem;
        line-height: 1.3;
    }

    .wa-inbox-badge {
        flex: none;
        border-radius: 999px;
        padding: 0.1rem 0.42rem;
        background: var(--wa-panel);
        color: var(--wa-muted);
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .wa-inbox-thread {
        background: color-mix(in oklab, var(--primary-500) 4%, var(--gray-50));
    }

    .dark .wa-inbox-thread {
        background: color-mix(in oklab, var(--gray-950) 45%, transparent);
    }

    .wa-inbox-thread-body {
        display: flex;
        flex: 1;
        min-height: 0;
        flex-direction: column;
        gap: 0.45rem;
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
        border-radius: 1.05rem;
        padding: 0.5rem 0.7rem 0.4rem;
        font-size: 0.9rem;
        line-height: 1.4;
    }

    .wa-bubble.is-in {
        background: white;
        box-shadow: 0 1px 2px color-mix(in oklab, var(--gray-950) 8%, transparent);
    }

    .dark .wa-bubble.is-in {
        background: color-mix(in oklab, var(--gray-800) 90%, transparent);
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
        border-radius: 0.65rem;
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
        border-radius: 0.75rem;
        background: var(--wa-panel);
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
        font-size: 0.72rem;
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
        border-radius: 0.55rem;
        object-fit: contain;
    }

    .wa-attachment-preview audio {
        width: min(22rem, 100%);
    }

    .wa-bubble-time {
        margin: 0;
        font-size: 0.65rem;
        line-height: 1;
        opacity: 0.7;
        text-align: right;
    }

    .wa-bubble-status {
        margin: 0;
        color: currentColor;
        font-size: 0.65rem;
        line-height: 1;
        opacity: 0.78;
        text-align: right;
    }

    .wa-inbox-composer {
        display: grid;
        gap: 0.45rem;
        padding: 0.6rem 0.7rem 0.65rem;
        border-top: 1px solid var(--wa-line);
        background: white;
    }

    .dark .wa-inbox-composer {
        background: color-mix(in oklab, var(--gray-900) 88%, transparent);
    }

    .wa-inbox-newchat {
        display: grid;
        gap: 0.4rem;
        grid-template-columns: minmax(0, 1fr);
    }

    .wa-inbox-newchat:has(select) {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr);
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
        width: 2.6rem;
        height: 2.6rem;
        margin: 0;
        border: 1px solid var(--wa-line);
        border-radius: 0.75rem;
        padding: 0;
        background: var(--wa-panel);
        cursor: pointer;
    }

    .wa-inbox-attach-toggle.is-on,
    .wa-inbox-attach-toggle[aria-expanded="true"] {
        background: color-mix(in oklab, var(--primary-500) 14%, transparent);
        border-color: color-mix(in oklab, var(--primary-500) 40%, var(--wa-line));
        color: var(--primary-700);
    }

    .wa-inbox input,
    .wa-inbox textarea,
    .wa-inbox select {
        width: 100%;
        min-width: 0;
        margin: 0;
        border: 1px solid var(--wa-line);
        border-radius: 0.75rem;
        padding: 0.55rem 0.7rem;
        background: var(--wa-panel);
        color: inherit;
        font: inherit;
        line-height: 1.4;
        outline: none;
        box-shadow: none;
    }

    .wa-inbox-file {
        position: relative;
        display: flex;
        align-items: center;
        min-height: 2.45rem;
        overflow: hidden;
        border: 1px solid var(--wa-line);
        border-radius: 0.75rem;
        padding: 0 0.7rem;
        background: var(--wa-panel);
        color: var(--wa-muted);
        cursor: pointer;
        font-size: 0.8rem;
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
        min-height: 2.6rem;
        max-height: 8rem;
        resize: vertical;
    }

    .wa-inbox-send {
        flex: none;
        height: 2.6rem;
        margin: 0;
        border: 0;
        border-radius: 0.75rem;
        padding: 0 1rem;
        background: var(--primary-600);
        color: white;
        font-weight: 650;
        cursor: pointer;
    }

    .wa-inbox-empty {
        margin: 0;
        color: var(--wa-muted);
        font-size: 0.9rem;
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
            max-height: 12rem;
        }

        .wa-inbox-thread-body {
            min-height: 10rem;
        }

        .wa-inbox-newchat:has(select),
        .wa-attachment-tools-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="wa-inbox" wire:poll.8s="refreshInbox">
    <section class="wa-inbox-col">
        <div class="wa-inbox-head">
            <p class="wa-inbox-kicker">Percakapan</p>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama, nomor, atau akun">
        </div>
        <div class="wa-inbox-scroll">
            @forelse ($this->chats as $chat)
                <button
                    type="button"
                    wire:click="selectChat(@js($chat['id']), @js($chat['title']), {{ (int) ($chat['provider_id'] ?? 0) }})"
                    class="wa-inbox-item {{ $this->isActiveChat($chat) ? 'is-active' : '' }}"
                >
                    <span class="wa-inbox-item-title">
                        <span class="wa-inbox-item-name">{{ $chat['title'] }}</span>
                        <span class="wa-inbox-badge">{{ $this->driverLabel($chat['driver'] ?? null) }}</span>
                    </span>
                    <p class="wa-inbox-item-preview">
                        {{ $chat['is_group'] ? 'Grup · ' : '' }}{{ ($chat['last_from_me'] ?? false) ? 'Anda: ' : '' }}{{ $chat['preview'] !== '' ? $chat['preview'] : $chat['id'] }}
                    </p>
                    @if (filled($chat['provider_name'] ?? null) || filled($chat['timestamp'] ?? null))
                        <p class="wa-inbox-item-foot">
                            {{ $chat['provider_name'] ?? '' }}{{ filled($chat['provider_name'] ?? null) && filled($chat['timestamp'] ?? null) ? ' · ' : '' }}{{ $chat['timestamp'] ?? '' }}
                        </p>
                    @endif
                </button>
            @empty
                <p class="wa-inbox-empty">Belum ada percakapan. Mulai obrolan baru, atau tunggu pesan masuk.</p>
            @endforelse
        </div>
    </section>

    <section class="wa-inbox-col wa-inbox-thread">
        <div class="wa-inbox-head">
            <p class="wa-inbox-thread-title">
                <span class="wa-inbox-thread-name">{{ $threadHeading }}</span>
                @if ($account && filled($this->chatId))
                    <span class="wa-inbox-badge">{{ $this->driverLabel($account->driver) }}</span>
                @endif
            </p>
            @if ($account && filled($this->chatId))
                <p class="wa-inbox-meta">Lewat {{ $account->name }}</p>
            @endif
            @if ($this->status)
                <p class="wa-inbox-meta">{{ $this->status }}</p>
            @endif
        </div>

        <div class="wa-inbox-thread-body" x-data x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
            @if ($this->composingNew)
                <p class="wa-inbox-empty">Pilih akun wrapping, isi nomor tujuan, lalu kirim. Balasan masuk di sisi kiri.</p>
            @elseif ($this->messages === [] && filled($this->chatId))
                <p class="wa-inbox-empty">Belum ada pesan di percakapan ini.</p>
            @elseif ($this->chatId === null && ! $this->composingNew)
                <p class="wa-inbox-empty">Pilih percakapan di kiri, atau klik Obrolan baru.</p>
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
                                            📎 {{ $message['attachment']['filename'] ?? 'Unduh lampiran' }}
                                        </a>
                                @endswitch
                            </div>
                        @elseif (isset($message['attachment']))
                            <div class="wa-bubble-attachment">📎 Lampiran sudah kedaluwarsa.</div>
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
                    @if ($channels->count() > 1)
                        <select wire:model="providerId">
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->id }}">
                                    {{ $this->driverLabel($channel->driver) }} · {{ $channel->name }}{{ $channel->is_active ? '' : ' (off)' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                    <input type="text" wire:model="newRecipient" placeholder="Nomor tujuan, contoh 081234567890">
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
    </section>
</div>
