<?php

namespace App\Models;

use App\Domain\Delivery\AttachmentKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'user_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'media_kind',
        'size',
        'checksum',
        'status',
        'last_referenced_at',
        'expires_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'last_referenced_at' => 'datetime',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kind(): AttachmentKind
    {
        return AttachmentKind::from((string) $this->media_kind);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active'
            && $this->deleted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && Storage::disk((string) $this->disk)->exists((string) $this->path);
    }
}
