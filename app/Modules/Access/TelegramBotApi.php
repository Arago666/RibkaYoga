<?php

namespace App\Modules\Access;

use RuntimeException;

/** Console-only credentials and verified TLS to the official API, including a DNS override. */
class TelegramBotApi
{
    private string $token;

    public function __construct()
    {
        if (! app()->runningInConsole() || ! function_exists('curl_init')) {
            throw new RuntimeException('Telegram worker requires the PHP CLI with cURL.');
        }
        $directory = getenv('CREDENTIALS_DIRECTORY');
        $path = $directory ? $directory.'/telegram-token' : '';
        $this->token = $path && is_readable($path) ? trim(file_get_contents($path)) : '';
        if (! preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/D', $this->token)) {
            throw new RuntimeException('The systemd Telegram credential is missing.');
        }
    }

    public function botId(): string
    {
        return explode(':', $this->token)[0];
    }

    public function call(string $method, array $parameters = []): array
    {
        if (! in_array($method, ['getMe', 'getWebhookInfo', 'getUpdates', 'sendMessage'], true)) {
            throw new RuntimeException('Unsupported Telegram worker method.');
        }
        $curl = curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode((object) $parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $address = (string) config('services.telegram.api_address');
        if ($address !== '') {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException('Invalid Telegram API address.');
            }
            // Host header, SNI and certificate validation still use api.telegram.org.
            curl_setopt($curl, CURLOPT_RESOLVE, ['api.telegram.org:443:'.$address]);
        }
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $result = json_decode($body, true);
        // Never expose cURL errors, request URLs or raw updates: they can contain the token or messages.
        if ($ok === false || $status !== 200 || ! is_array($result) || ($result['ok'] ?? false) !== true || ! is_array($result['result'] ?? null)) {
            throw new RuntimeException('Telegram API request failed (HTTP '.(int) $status.').');
        }

        return $result['result'];
    }
}
