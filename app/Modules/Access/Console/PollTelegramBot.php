<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\TelegramBotApi;
use App\Modules\Access\TelegramOpeningReply;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PollTelegramBot extends Command
{
    protected $signature = 'ribka:telegram-poll {--check : Verify the official API without reading messages} {--once : Process one batch and exit}';

    protected $description = 'Получать команды бота через защищённое исходящее соединение';

    public function handle(TelegramBotApi $api, TelegramOpeningReply $replies): int
    {
        try {
            $me = $api->call('getMe');
            if (($me['username'] ?? '') !== config('services.telegram.username')) {
                $this->error('Настроен другой Telegram-бот. Запуск остановлен.');

                return self::FAILURE;
            }
            $webhook = $api->call('getWebhookInfo');
            $this->info('Официальный API доступен: @'.$me['username']);
            if ($this->option('check')) {
                $this->line('Webhook: '.(empty($webhook['url']) ? 'отключён' : 'настроен'));

                return self::SUCCESS;
            }
            if (! empty($webhook['url'])) {
                $this->error('Перед polling необходимо отключить webhook без удаления ожидающих команд.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('Не удалось проверить официальный Telegram API. Секреты и ответы не выводятся.');

            return self::FAILURE;
        }
        $prefix = 'telegram:bot:'.$api->botId();
        $stopping = false;
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$stopping) {
                $stopping = true;
            });
        }
        do {
            $lock = Cache::lock($prefix.':worker', 600);
            if (! $lock->get()) {
                $this->error('Уже работает другой процесс этого бота.');

                return self::FAILURE;
            }
            try {
                $updates = $api->call('getUpdates', [
                    'offset' => (int) Cache::get($prefix.':offset', 0),
                    'timeout' => $this->option('once') ? 0 : 25,
                    'limit' => 10,
                    'allowed_updates' => ['message'],
                ]);
                foreach ($updates as $update) {
                    if (! is_array($update) || ! is_int($update['update_id'] ?? null)) {
                        continue;
                    }
                    $id = $update['update_id'];
                    $done = $prefix.':replied:'.$id;
                    if (! Cache::get($done)) {
                        $reply = $replies->forUpdate($update);
                        if ($reply) {
                            $api->call('sendMessage', $reply);
                        }
                        Cache::put($done, true, now()->addDays(2));
                    }
                    Cache::forever($prefix.':offset', $id + 1);
                }
                if ($this->option('once')) {
                    $this->info('Обработано обновлений: '.count($updates));
                }
            } catch (Throwable) {
                $this->warn('Связь с Telegram временно недоступна; команды будут получены повторно.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(5);
            } finally {
                $lock->release();
            }
        } while (! $stopping && ! $this->option('once'));

        return self::SUCCESS;
    }
}
