<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'login',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/practices',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web', 'throttle:access'],
    'limiters' => ['login' => 'login'],
    'views' => true,
    'features' => [Features::registration(), Features::resetPasswords()],
];
