<x-layouts.auth
    title="Email verification"
    heading="Email verification"
    description="Please verify your email address by clicking on the link we just emailed to you."
>
    <x-auth.status :status="$status === 'verification-link-sent' ? $status : null">
        A new verification link has been sent to the email address you provided during registration.
    </x-auth.status>

    <form method="POST" action="{{ route('verification.send') }}" class="space-y-6 text-center">
        @csrf
        <x-ui.submit variant="secondary">Resend verification email</x-ui.submit>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="mx-auto block text-sm text-primary underline-offset-4 hover:underline">
            Log out
        </button>
    </form>
</x-layouts.auth>
