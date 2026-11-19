<?php

namespace App\Modules\Access\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordRecoveryController extends PasswordResetLinkController
{
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        try {
            parent::store($request);
        } catch (TransportExceptionInterface $exception) {
            Log::warning('Password reset mail transport failed.', ['transport_error' => $exception::class]);

            throw ValidationException::withMessages(['email' => 'Письмо не отправилось. Повторите запрос через минуту или обратитесь в поддержку.']);
        }

        // Use the same response for existing, missing and throttled accounts.
        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
