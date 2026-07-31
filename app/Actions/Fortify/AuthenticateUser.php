<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticateUser
{
    public function authenticate(string $email, string $password): ?User
    {
        $normalizedEmail = Str::lower(trim($email));

        if ($normalizedEmail === '' || $password === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            return null;
        }

        return $user;
    }
}
