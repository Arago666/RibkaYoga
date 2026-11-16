<?php

namespace App\Modules\Access\Http;

use App\Modules\Access\TelegramOpeningReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Opens the existing authenticated app. Telegram never grants a site account. */
class TelegramBotController
{
    public function __invoke(Request $request, TelegramOpeningReply $opening): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $input = $request->validate(['update_id' => 'required|integer|min:0', 'message' => 'sometimes|array']);
        $reply = $opening->forUpdate($input);
        if (! $reply) {
            return response()->json(['ok' => true]);
        }
        // A verified webhook reply uses Telegram's response API: no token in logs or HTTP URLs.
        if (! Cache::add('telegram:opening:'.$input['update_id'], true, now()->addDays(2))) {
            return response()->json(['ok' => true]);
        }

        return response()->json(['method' => 'sendMessage', ...$reply]);
    }
}
