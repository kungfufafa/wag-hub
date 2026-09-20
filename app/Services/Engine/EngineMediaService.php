<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Models\ClientApplication;
use App\Models\EngineMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class EngineMediaService
{
    public function upload(ClientApplication $application, UploadedFile $file, string $type): EngineMedia
    {
        if (! $file->isValid() || $file->getSize() > $this->maximumBytes()) {
            throw new WhatsAppEngineException('Media must be a valid file of at most 16 MiB.', 422, 'invalid_media');
        }
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes)) {
            throw new WhatsAppEngineException('Uploaded media could not be read.', 422, 'invalid_media');
        }

        return $this->storeBytes($application, $bytes, $type, $file->getClientOriginalName(), true);
    }

    public function incoming(ClientApplication $application, array $data): ?EngineMedia
    {
        if (! is_string($data['media_base64'] ?? null)) {
            return null;
        }
        if (strlen($data['media_base64']) > (int) ceil($this->maximumBytes() / 3) * 4 + 4) {
            throw new WhatsAppEngineException('Incoming media exceeds the size limit.', 422, 'media_too_large');
        }
        $bytes = base64_decode($data['media_base64'], true);
        if ($bytes === false) {
            throw new WhatsAppEngineException('Incoming media encoding is invalid.', 422, 'invalid_media');
        }

        return $this->storeBytes($application, $bytes, (string) ($data['type'] ?? 'document'),
            (string) ($data['filename'] ?? 'attachment'), false);
    }

    public function owned(ClientApplication $application, string $uuid): EngineMedia
    {
        $media = EngineMedia::query()->where('client_application_id', $application->id)->where('uuid', $uuid)->first();
        if ($media === null) {
            throw new WhatsAppEngineException('Media not found.', 404, 'media_not_found');
        }
        if ($media->expires_at->isPast()) {
            throw new WhatsAppEngineException('Media has expired.', 410, 'media_expired');
        }

        return $media;
    }

    public function bytes(EngineMedia $media): string
    {
        $bytes = Storage::disk($media->disk)->get($media->path);
        if (! is_string($bytes) || strlen($bytes) !== $media->size || ! hash_equals($media->sha256, hash('sha256', $bytes))) {
            throw new WhatsAppEngineException('Media is unavailable or corrupt.', 503, 'media_unavailable');
        }

        return $bytes;
    }

    public function snapshot(EngineMedia $media): array
    {
        return ['id' => $media->uuid, 'type' => $media->type, 'filename' => $media->filename,
            'mimetype' => $media->mimetype, 'size' => $media->size,
            'expires_at' => $media->expires_at->toISOString()];
    }

    public function maximumBytes(): int
    {
        return min(16777216, max(1, (int) config('engine_v2.media_max_bytes', 16777216)));
    }

    private function storeBytes(ClientApplication $application, string $bytes, string $type, string $filename, bool $convertVoice): EngineMedia
    {
        if ($bytes === '' || strlen($bytes) > $this->maximumBytes() || ! in_array($type, ['image', 'document', 'voice'], true)) {
            throw new WhatsAppEngineException('Media type or size is invalid.', 422, 'invalid_media');
        }
        $mimetype = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if ($type === 'image' && (! in_array($mimetype, ['image/jpeg', 'image/png'], true) || @getimagesizefromstring($bytes) === false)) {
            throw new WhatsAppEngineException('Images must contain JPEG or PNG data.', 422, 'invalid_media_type');
        }
        if ($type === 'voice') {
            if (! str_starts_with($mimetype, 'audio/') && ! in_array($mimetype, ['application/ogg', 'video/mp4', 'video/webm'], true)) {
                throw new WhatsAppEngineException('Voice files must contain audio.', 422, 'invalid_media_type');
            }
            if ($convertVoice) {
                $bytes = $this->convertVoice($bytes);
                $mimetype = 'audio/ogg; codecs=opus';
                $filename = pathinfo($filename, PATHINFO_FILENAME).'.ogg';
            }
        }
        $uuid = (string) Str::uuid();
        $disk = (string) config('engine_v2.media_disk', 'local');
        if ($disk === 'public') {
            throw new WhatsAppEngineException('Engine media requires a private storage disk.', 503, 'media_storage_not_private');
        }
        $path = 'engine-media/'.$application->uuid.'/'.$uuid;
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw new WhatsAppEngineException('Media storage is unavailable.', 503, 'media_unavailable');
        }
        try {
            return EngineMedia::query()->create([
                'uuid' => $uuid, 'client_application_id' => $application->id, 'type' => $type,
                'disk' => $disk, 'path' => $path,
                'filename' => mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/', '_', $filename) ?: 'attachment', 0, 200),
                'mimetype' => $mimetype, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'expires_at' => now()->addDays((int) config('engine_v2.event_retention_days', 7)),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    private function convertVoice(string $bytes): string
    {
        $input = tempnam(sys_get_temp_dir(), 'wag-voice-in-');
        $output = tempnam(sys_get_temp_dir(), 'wag-voice-out-');
        if ($input === false || $output === false) {
            throw new WhatsAppEngineException('Voice conversion storage is unavailable.', 503, 'voice_conversion_unavailable');
        }
        try {
            file_put_contents($input, $bytes);
            $timeout = min(60, max(1, (int) config('engine_v2.voice_timeout_seconds', 30)));
            $probe = new Process([(string) config('engine_v2.ffprobe_binary', 'ffprobe'), '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $input]);
            $probe->setTimeout(min(10, $timeout))->run();
            if (in_array($probe->getExitCode(), [126, 127], true)) {
                throw new WhatsAppEngineException('Voice conversion tools are not installed.', 503, 'voice_conversion_unavailable');
            }
            $duration = trim($probe->getOutput());
            $maximumSeconds = min(300, max(1, (int) config('engine_v2.voice_max_seconds', 300)));
            if (! $probe->isSuccessful() || ! is_numeric($duration) || (float) $duration <= 0 || (float) $duration > $maximumSeconds) {
                throw new WhatsAppEngineException('Voice duration must be valid and no longer than five minutes.', 422, 'invalid_voice_duration');
            }
            $convert = new Process([(string) config('engine_v2.ffmpeg_binary', 'ffmpeg'), '-nostdin', '-v', 'error', '-y',
                '-protocol_whitelist', 'file,pipe', '-threads', '1', '-i', $input, '-vn', '-ac', '1', '-ar', '48000',
                '-c:a', 'libopus', '-b:a', '32k', '-t', (string) $maximumSeconds, '-fs', (string) $this->maximumBytes(), '-f', 'ogg', $output]);
            $convert->setTimeout($timeout)->run();
            if (in_array($convert->getExitCode(), [126, 127], true)) {
                throw new WhatsAppEngineException('Voice conversion tools are not installed.', 503, 'voice_conversion_unavailable');
            }
            $result = file_get_contents($output);
            if (! $convert->isSuccessful() || ! is_string($result) || $result === '' || strlen($result) >= $this->maximumBytes()) {
                throw new WhatsAppEngineException('Voice conversion failed.', 422, 'voice_conversion_failed');
            }

            return $result;
        } catch (WhatsAppEngineException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WhatsAppEngineException('Voice conversion is unavailable or exceeded its time limit.', 503, 'voice_conversion_unavailable');
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
