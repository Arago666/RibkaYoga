<?php

namespace App\Modules\Access\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Opens the existing authenticated app. Telegram never grants a site account. */
class TelegramBotController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $input = $request->validate(['update_id' => 'required|integer|min:0', 'message' => 'sometimes|array']);
        $message = $input['message'] ?? [];
        $chat = $message['chat'] ?? null;
        if (! is_array($chat) || ($chat['type'] ?? null) !== 'private' || ! is_int($chat['id'] ?? null)) {
            return response()->json(['ok' => true]);
        }
        $text = is_string($message['text'] ?? null) ? $message['text'] : '';
        $command = explode(' ', $text)[0];
        $command = strtolower(explode('@', $command)[0]);
        if (! in_array($command, ['/start', '/app', '/help'], true)) {
            return response()->json(['ok' => true]);
        }
        $url = (string) config('services.telegram.mini_app_url');
        abort_unless(str_starts_with($url, 'https://'), 503);
        // A verified webhook reply uses Telegram's response API: no token in logs or HTTP URLs.
        if (! Cache::add('telegram:opening:'.$input['update_id'], true, now()->addDays(2))) {
            return response()->json(['ok' => true]);
        }

        return response()->json([
            'method' => 'sendMessage',
            'chat_id' => $message['chat']['id'],
            'text' => "Добро пожаловать в RibkaYoga 🌿\n\nВаши практики, библиотека асан, медитация и скетчбук — в одном приложении. Нажмите кнопку ниже, чтобы открыть его. Войдите в свой кабинет сайта или создайте аккаунт.",
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Открыть RibkaYoga', 'web_app' => ['url' => $url]]]]],
        ]);
    }
}
