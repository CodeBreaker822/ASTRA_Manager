<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/web-diagnostic-user.php <email> <password>\n");
    exit(1);
}

$user = User::query()->updateOrCreate(
    ['email' => $email],
    [
        'name' => 'JERVA Web Diagnostic',
        'password' => Hash::make($password),
        'plan' => 'pro',
        'user_status' => 'active',
        'email_verified_at' => now(),
    ],
);

$user->forceFill([
    'plan' => 'pro',
    'user_status' => 'active',
    'email_verified_at' => now(),
])->save();

echo $user->id;
