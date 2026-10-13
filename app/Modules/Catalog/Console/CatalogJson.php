<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Catalog\Services\CatalogExchange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class CatalogJson extends Command
{
    protected $signature = 'ribka:catalog-json {mode : export или import} {file : JSON-файл} {--user= : Логин пользователя}';

    protected $description = 'Экспорт задания для локального Codex и импорт готовых изображений';

    public function handle(CatalogExchange $exchange): int
    {
        $user = User::where('login', mb_strtolower((string) $this->option('user')))->firstOrFail();
        Auth::setUser($user);
        $path = $this->argument('file');
        if ($this->argument('mode') === 'export') {
            if (file_exists($path)) {
                $this->error('Файл уже существует; выберите новое имя.');

                return self::FAILURE;
            }
            $encoded = json_encode($exchange->export([]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $encoded) === false) {
                $this->error('Не удалось записать JSON-файл. Проверьте папку и права.');

                return self::FAILURE;
            }
            $this->info('JSON сохранён: '.$path);

            return self::SUCCESS;
        }
        if ($this->argument('mode') !== 'import' || ! is_file($path) || filesize($path) > 48 * 1024 * 1024) {
            $this->error('Нужен import и существующий JSON до 48 МБ.');

            return self::FAILURE;
        }
        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $root = realpath(dirname($path));
        if (! is_array($payload['items'] ?? null) || ! array_is_list($payload['items']) || count($payload['items']) < 1 || count($payload['items']) > 100) {
            throw new \RuntimeException('JSON должен содержать от 1 до 100 элементов items.');
        }
        $imageBytes = 0;
        foreach ($payload['items'] as &$item) {
            if (! is_array($item)) {
                throw new \RuntimeException('Некорректный элемент items.');
            }
            if (isset($item['image_data'])) {
                if (! is_string($item['image_data'])) {
                    throw new \RuntimeException('image_data должен быть строкой.');
                }
                $imageBytes += (int) ceil(strlen($item['image_data']) * 3 / 4);
            }
            if (! isset($item['image_data']) && isset($item['image_file'])) {
                if (! is_string($item['image_file']) || str_contains($item['image_file'], ':') || str_starts_with($item['image_file'], '/') || str_starts_with($item['image_file'], '\\')) {
                    throw new \RuntimeException('Используйте относительный путь изображения.');
                }
                $file = realpath($root.DIRECTORY_SEPARATOR.$item['image_file']);
                $base = rtrim(str_replace('\\', '/', $root), '/').'/';
                $candidate = str_replace('\\', '/', (string) $file);
                if (PHP_OS_FAMILY === 'Windows') {
                    $candidate = strtolower($candidate);
                    $base = strtolower($base);
                }
                if (! $file || ! str_starts_with($candidate, $base) || ! is_file($file) || filesize($file) > 10 * 1024 * 1024) {
                    throw new \RuntimeException('Изображение должно находиться внутри папки JSON и быть до 10 МБ.');
                }
                $imageBytes += filesize($file);
                if ($imageBytes > 32 * 1024 * 1024) {
                    throw new \RuntimeException('Выберите меньше изображений: суммарный размер ограничен 32 МБ.');
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
                $item['image_data'] = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file));
            }
            if ($imageBytes > 32 * 1024 * 1024) {
                throw new \RuntimeException('Выберите меньше изображений: суммарный размер ограничен 32 МБ.');
            }
        }
        unset($item);
        $result = $exchange->import($payload);
        $this->info($result['message']);

        return self::SUCCESS;
    }
}
