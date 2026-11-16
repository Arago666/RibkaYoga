<?php

namespace App\Modules\Access;

use Illuminate\Validation\ValidationException;

/** Official production Ed25519 verification; no bot credential is needed by PHP-FPM.
 * https://core.telegram.org/bots/webapps#validating-data-for-third-party-use
 */
class TelegramIdentityVerifier
{
    private const PUBLIC_KEY = 'e7bf03a2fa4602af4580703d88dda5bb59f32ed8b02a56c187fe7d34caed242d';

    public function verify(string $input): array
    {
        abort_unless(function_exists('sodium_crypto_sign_verify_detached'), 503, 'Вход через Telegram временно недоступен.');
        $botId = (string) config('services.telegram.bot_id');
        abort_unless(preg_match('/\A[1-9][0-9]{0,19}\z/', $botId), 503, 'Вход через Telegram ещё не настроен.');
        $fields = [];
        if ($input === '' || strlen($input) > 16384) {
            $this->invalid();
        }
        // Reject duplicate/array keys rather than accepting ambiguous parse_str results.
        foreach (explode('&', $input) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if (count($parts) !== 2 || ! preg_match('/\A[a-z_]+\z/', $key)
                || array_key_exists($key, $fields) || preg_match('/[\x00\r\n]/', $value)) {
                $this->invalid();
            }
            $fields[$key] = $value;
        }
        $encoded = $fields['signature'] ?? '';
        if (! preg_match('/\A[A-Za-z0-9_-]{86}(?:==)?\z/', $encoded)) {
            $this->invalid();
        }
        $signature = base64_decode(strtr(rtrim($encoded, '='), '-_', '+/').'==', true);
        unset($fields['hash'], $fields['signature']);
        ksort($fields, SORT_STRING);
        $check = $botId.":WebAppData\n".implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($fields), $fields));
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $check, hex2bin(self::PUBLIC_KEY))) {
            $this->invalid();
        }
        $date = $fields['auth_date'] ?? '';
        if (! ctype_digit($date) || (int) $date < time() - 900 || (int) $date > time() + 30) {
            $this->invalid();
        }
        $user = json_decode($fields['user'] ?? '', true);
        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] < 1 || $user['id'] > 4503599627370495
            || ($user['is_bot'] ?? false) !== false || ! is_string($user['first_name'] ?? null)) {
            $this->invalid();
        }
        $username = $user['username'] ?? null;
        if ($username !== null && (! is_string($username) || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $username))) {
            $this->invalid();
        }

        return [
            'bot_id' => $botId, 'telegram_id' => (string) $user['id'], 'username' => $username,
            'name' => mb_substr(trim($user['first_name'].' '.(is_string($user['last_name'] ?? null) ? $user['last_name'] : '')), 0, 120) ?: 'Участник Telegram',
            'proof' => hash('sha256', $signature),
        ];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['telegram' => 'Не удалось подтвердить вход. Закройте Mini App и откройте его снова из Telegram.']);
    }
}
