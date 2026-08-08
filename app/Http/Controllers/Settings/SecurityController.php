<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): View
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'twoFactorEnabled' => false,
            'requiresConfirmation' => false,
            'pendingSetup' => false,
            'qrCodeSvg' => null,
            'setupKey' => null,
            'recoveryCodes' => [],
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $user = $request->user();
            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');

            // A secret without a confirmation means setup was started but not
            // finished, so the QR code has to be shown again.
            $props['pendingSetup'] = $user->two_factor_secret !== null && ! $props['twoFactorEnabled'];

            if ($user->two_factor_secret !== null) {
                $props['qrCodeSvg'] = $user->twoFactorQrCodeSvg();
                $props['setupKey'] = decrypt($user->two_factor_secret);
            }

            if ($props['twoFactorEnabled']) {
                $props['recoveryCodes'] = $user->recoveryCodes();
            }
        }

        return view('settings.security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Password updated.')]);
    }
}
