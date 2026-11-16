<?php

namespace App\Modules\Access;

/** Only a private-chat command opens the existing authenticated site. */
class TelegramOpeningReply
{
    public function forUpdate(array $update): ?array
    {
        $message = $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        if (! is_array($chat) || ($chat['type'] ?? null) !== 'private' || ! is_int($chat['id'] ?? null)) {
            return null;
        }
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $command = strtolower(explode('@', preg_split('/\s+/', $text)[0])[0]);
        if (! in_array($command, ['/start', '/app', '/help'], true)) {
            return null;
        }
        $url = (string) config('services.telegram.mini_app_url');
        if (! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Telegram Mini App requires HTTPS.');
        }

        return [
            'chat_id' => $chat['id'],
            'text' => "Добро пожаловать в RibkaYoga 🌿\n\nВаши практики, библиотека асан, медитация и скетчбук — в одном приложении. Нажмите кнопку ниже, чтобы открыть его. Войдите в свой кабинет сайта или создайте аккаунт.",
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Открыть RibkaYoga', 'web_app' => ['url' => $url]]]]],
        ];
    }
}
