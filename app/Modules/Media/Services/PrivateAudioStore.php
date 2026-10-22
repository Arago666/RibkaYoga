<?php

namespace App\Modules\Media\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\Models\AudioAsset;
use App\Modules\Media\PublicApi\AudioStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PrivateAudioStore implements AudioStore
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function limits(): array
    {
        return ['max_bytes' => (int) config('ribka_audio.max_bytes'), 'max_seconds' => (int) config('ribka_audio.max_seconds'), 'max_library_bytes' => (int) config('ribka_audio.max_library_bytes')];
    }

    public function list(bool $archived = false): array
    {
        $query = $this->query();
        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        return $query->orderByDesc('created_at')->get()->map(fn (AudioAsset $asset) => $this->data($asset))->all();
    }

    public function store(UploadedFile $file, string $title): array
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > $this->limits()['max_bytes']) {
            throw ValidationException::withMessages(['audio' => 'Не удалось загрузить файл либо превышен допустимый размер.']);
        }
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)) {
            throw ValidationException::withMessages(['audio' => 'Поддерживаются настоящие MP3, M4A/AAC и WAV.']);
        }
        try {
            $probe = new Process([(string) config('ribka_audio.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,duration:stream_disposition=attached_pic', '-of', 'json', $path]);
            $probe->setTimeout(20)->mustRun();
            $info = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $audio = array_values(array_filter($info['streams'] ?? [], fn ($stream) => ($stream['codec_type'] ?? null) === 'audio'));
            $video = array_filter($info['streams'] ?? [], fn ($stream) => ($stream['codec_type'] ?? null) === 'video' && ! ($stream['disposition']['attached_pic'] ?? false));
            $format = $info['format']['format_name'] ?? '';
            $codec = $audio[0]['codec_name'] ?? '';
            $seconds = (float) ($info['format']['duration'] ?? $audio[0]['duration'] ?? 0);
            $extension = match (true) {
                $format === 'mp3' && $codec === 'mp3' => 'mp3',
                $format === 'wav' && str_starts_with($codec, 'pcm_') => 'wav',
                str_contains($format, 'm4a') && $codec === 'aac' => 'm4a',
                default => null,
            };
            if (! $extension || count($audio) !== 1 || $video || ! is_finite($seconds) || $seconds <= 0 || $seconds > $this->limits()['max_seconds']) {
                throw ValidationException::withMessages(['audio' => 'Нужен аудиофайл MP3, M4A/AAC или PCM WAV допустимой длительности, без видеодорожки.']);
            }
            $decode = new Process([(string) config('ribka_audio.ffmpeg'), '-v', 'error', '-xerror', '-nostdin', '-protocol_whitelist', 'file,pipe', '-i', $path, '-map', '0:a:0', '-f', 'null', '-']);
            $decode->setTimeout((int) config('ribka_audio.decode_timeout'))->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException|\JsonException $error) {
            report($error);
            throw ValidationException::withMessages(['audio' => 'Не удалось декодировать файл целиком. Проверьте формат и длительность.']);
        } catch (\Symfony\Component\Process\Exception\RuntimeException $error) {
            report($error);
            abort(503, 'Проверка аудио временно недоступна: администратору нужно настроить ffmpeg и ffprobe.');
        }
        $id = (string) Str::uuid();
        $relative = 'audio/'.$this->workspace->id().'/'.$this->workspace->userId().'/'.$id.'.'.$extension;
        $disk = Storage::disk('local');
        try {
            return DB::transaction(function () use ($file, $title, $path, $codec, $seconds, $extension, $id, $relative, $disk) {
                // A Media-owned lock prevents concurrent uploads bypassing quota.
                DB::table('media_audio_libraries')->insertOrIgnore(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id(), 'owner_id' => $this->workspace->userId()]);
                DB::table('media_audio_libraries')->where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId())->lockForUpdate()->first();
                if ($this->query()->sum('bytes') + $file->getSize() > $this->limits()['max_library_bytes']) {
                    throw ValidationException::withMessages(['audio' => 'Достигнут общий объём личной аудиобиблиотеки, включая архив.']);
                }
                $handle = fopen($path, 'rb');
                try {
                    if (! is_resource($handle) || ! $disk->put($relative, $handle)) {
                        throw new \RuntimeException('Не удалось сохранить аудио.');
                    }
                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                $asset = AudioAsset::create([
                    'id' => $id, 'workspace_id' => $this->workspace->id(), 'owner_id' => $this->workspace->userId(),
                    'title' => trim($title), 'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'disk' => 'local', 'path' => $relative, 'mime' => ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4'][$extension],
                    'codec' => $codec, 'bytes' => $file->getSize(), 'duration_ms' => (int) round($seconds * 1000), 'sha256' => hash_file('sha256', $path),
                    'revision' => 1,
                ]);

                return $this->data($asset);
            });
        } catch (\Throwable $error) {
            $disk->delete($relative);
            throw $error;
        }
    }

    public function get(string $id, bool $allowArchived = false): array
    {
        $asset = $this->find($id);
        abort_if(! $allowArchived && $asset->archived_at, 422, 'Аудиозапись находится в архиве.');

        return $this->data($asset);
    }

    public function update(string $id, int $revision, ?string $title, ?bool $archived): array
    {
        return DB::transaction(function () use ($id, $revision, $title, $archived) {
            $asset = $this->query()->lockForUpdate()->findOrFail($id);
            abort_unless($asset->revision === $revision, 409, 'Аудиозапись изменена в другом окне. Обновите список.');
            if ($title !== null) {
                $asset->title = trim($title);
            }
            if ($archived !== null) {
                $asset->archived_at = $archived ? now() : null;
            }
            $asset->revision++;
            $asset->save();

            return $this->data($asset);
        });
    }

    public function stream(string $id): array
    {
        $asset = $this->find($id);
        $path = Storage::disk($asset->disk)->path($asset->path);
        abort_unless(is_file($path), 404);

        return ['path' => $path, 'mime' => $asset->mime];
    }

    private function query(): Builder
    {
        return AudioAsset::where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId());
    }

    private function find(string $id): AudioAsset
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->query()->findOrFail($id);
    }

    private function data(AudioAsset $asset): array
    {
        return ['id' => $asset->id, 'title' => $asset->title, 'original_filename' => $asset->original_filename, 'mime' => $asset->mime, 'bytes' => $asset->bytes, 'duration_ms' => $asset->duration_ms, 'revision' => $asset->revision, 'archived_at' => $asset->archived_at?->toISOString(), 'stream_url' => '/media/audio/'.$asset->id];
    }
}
