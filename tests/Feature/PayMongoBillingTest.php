<?php

use App\Models\BillingTransaction;
use App\Models\PlanTier;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('paymongo checkout creates a billing transaction and redirects to hosted checkout', function () {
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test_123',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test_123',
                ],
            ],
        ]),
    ]);

    config(['services.paymongo.secret_key' => 'sk_test_123']);

    PlanTier::query()->updateOrCreate(
        ['key' => 'payg'],
        [
            'name' => 'Pay as you go',
            'tagline' => 'Buy extra minutes only when you need them.',
            'monthly_price' => null,
            'yearly_price' => null,
            'price_label' => 'Pay as you go',
            'price_per_second' => 0.05277778,
            'upload_price_per_hour' => 190,
            'live_price_per_hour' => 240,
            'llm_price' => 5,
            'polish_price_per_character' => 0.0002,
            'summary_price_per_character' => 0.0003,
            'minutes' => 600,
            'free_polish_uses_per_day' => 0,
            'free_summary_uses_per_day' => 0,
            'polish_characters' => 100000,
            'summary_characters' => 80000,
            'cta' => 'Buy minutes',
            'featured' => true,
            'features' => [],
            'entitlements' => [],
            'sort_order' => 1,
            'is_active' => true,
        ],
    );
    Cache::flush();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'payg'])
        ->assertRedirect('https://checkout.paymongo.com/cs_test_123');

    $transaction = BillingTransaction::query()->firstOrFail();

    expect($transaction->user_id)->toBe($user->id)
        ->and($transaction->plan)->toBe('payg')
        ->and($transaction->provider)->toBe('paymongo')
        ->and($transaction->amount)->toBe(190000)
        ->and($transaction->checkout_session_id)->toBe('cs_test_123')
        ->and($transaction->status)->toBe('checkout_created');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['metadata']['plan'] === 'payg'
        && $request['data']['attributes']['metadata']['credit_type'] === 'audio'
        && $request['data']['attributes']['metadata']['credit_minutes'] === '600'
        && $request['data']['attributes']['metadata']['polish_characters'] === '100000'
        && $request['data']['attributes']['metadata']['summary_characters'] === '80000'
        && $request['data']['attributes']['metadata']['upload_price_per_hour'] === '190'
        && $request['data']['attributes']['metadata']['live_price_per_hour'] === '240'
        && $request['data']['attributes']['metadata']['llm_price'] === '5'
        && $request['data']['attributes']['metadata']['polish_price_per_character'] === '0.0002'
        && $request['data']['attributes']['metadata']['summary_price_per_character'] === '0.0003'
        && $request['data']['attributes']['line_items'][0]['amount'] === 190000);
});

test('paymongo checkout creates polish character credit sessions', function () {
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test_polish',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test_polish',
                ],
            ],
        ]),
    ]);

    config(['services.paymongo.secret_key' => 'sk_test_123']);
    Cache::flush();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'payg', 'credit_type' => 'polish'])
        ->assertRedirect('https://checkout.paymongo.com/cs_test_polish');

    $transaction = BillingTransaction::query()->firstOrFail();

    expect($transaction->amount)->toBe(2000);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['metadata']['credit_type'] === 'polish'
        && $request['data']['attributes']['metadata']['polish_characters'] === '100000'
        && $request['data']['attributes']['line_items'][0]['amount'] === 2000);
});

test('paymongo webhook adds minute credits after a verified paid event', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'payg',
        'reference' => 'JERVA-1-PAID',
        'checkout_session_id' => 'cs_test_paid',
        'status' => 'checkout_created',
        'amount' => 190000,
        'currency' => 'PHP',
    ]);
    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_paid',
                    'attributes' => [
                        'reference_number' => $transaction->reference,
                        'metadata' => ['plan' => 'payg'],
                        'payments' => [
                            ['id' => 'pay_test_123'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $this->call(
        'POST',
        route('paymongo.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
        ],
        $body,
    )->assertOk();

    expect($user->refresh()->plan)->toBe('free')
        ->and($user->credit_seconds)->toBe(600 * 60)
        ->and($transaction->refresh()->status)->toBe('paid')
        ->and($transaction->payment_id)->toBe('pay_test_123')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('paymongo webhook adds text credits based on credit type', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'payg',
        'reference' => 'JERVA-1-POLISH',
        'checkout_session_id' => 'cs_test_polish_paid',
        'status' => 'checkout_created',
        'amount' => 2000,
        'currency' => 'PHP',
    ]);
    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_polish_paid',
                    'attributes' => [
                        'reference_number' => $transaction->reference,
                        'metadata' => [
                            'plan' => 'payg',
                            'credit_type' => 'polish',
                            'polish_characters' => '12345',
                        ],
                        'payments' => [
                            ['id' => 'pay_test_polish'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $this->call(
        'POST',
        route('paymongo.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
        ],
        $body,
    )->assertOk();

    expect($user->refresh()->polish_credit_characters)->toBe(12345)
        ->and($user->summary_credit_characters)->toBe(0)
        ->and($user->credit_seconds)->toBe(0);
});

test('paymongo webhook does not add credits twice for duplicate paid events', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'payg',
        'reference' => 'JERVA-1-DUPLICATE',
        'checkout_session_id' => 'cs_test_duplicate',
        'status' => 'checkout_created',
        'amount' => 190000,
        'currency' => 'PHP',
    ]);
    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_duplicate',
                    'attributes' => [
                        'reference_number' => $transaction->reference,
                        'metadata' => ['plan' => 'payg'],
                        'payments' => [
                            ['id' => 'pay_test_duplicate'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
    ];

    $this->call('POST', route('paymongo.webhook'), [], [], [], $server, $body)
        ->assertOk();
    $this->call('POST', route('paymongo.webhook'), [], [], [], $server, $body)
        ->assertOk();

    expect($transaction->refresh()->status)->toBe('paid')
        ->and($user->refresh()->credit_seconds)->toBe(600 * 60);
});

test('paymongo webhook rejects invalid signatures', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $this->withHeaders(['PayMongo-Signature' => 't=123,te=bad'])
        ->postJson(route('paymongo.webhook'), ['data' => []])
        ->assertUnauthorized();
});
