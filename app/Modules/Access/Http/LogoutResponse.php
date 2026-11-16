<?php

namespace App\Modules\Access\Http;

class LogoutResponse extends \Laravel\Fortify\Http\Responses\LogoutResponse
{
    public function toResponse($request)
    {
        if (! $request->wantsJson() && $request->boolean('mini_app')) {
            return redirect()->route('mini-app');
        }

        return parent::toResponse($request);
    }
}
