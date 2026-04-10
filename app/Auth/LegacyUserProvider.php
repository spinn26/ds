<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class LegacyUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (method_exists($user, 'validatePassword')) {
            return $user->validatePassword($credentials['password']);
        }

        return parent::validateCredentials($user, $credentials);
    }
}
