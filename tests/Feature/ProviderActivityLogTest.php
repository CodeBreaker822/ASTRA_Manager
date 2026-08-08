<?php

use App\Models\TranscriptionApiRequestLog;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\Transcription\ProviderFallbackLogger;
use Illuminate\Support\Str;

test('provider attempt logger records primary and fallback successes', function () {
    $logger = app(ProviderFallbackLogger::class);

    $logger->success('transcriber', 'transcribe', [
        'provider' => 'groq_transcription',
        'model' => 'whisper-large-v3-turbo',
    ], 0);
    $logger->success('text_fixer', 'summarize', [
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
    ], 1);

    $this->assertDatabaseHas(TranscriptionApiRequestLog::class, [
        'operation' => 'transcribe_provider',
        'status' => 'provider_succeeded',
        'provider' => 'groq_transcription',
        'model' => 'whisper-large-v3-turbo',
    ]);
    $this->assertDatabaseHas(TranscriptionApiRequestLog::class, [
        'operation' => 'summarize_provider',
        'status' => 'fallback_succeeded',
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
    ]);
});

test('provider activity endpoint returns bounded numbered pages including cleanup logs', function () {
    $manager = createProviderActivityLogManager();
    $now = now();

    for ($index = 1; $index <= 60; $index++) {
        TranscriptionApiRequestLog::query()->create([
            'request_id' => (string) Str::uuid(),
            'operation' => 'transcribe_provider',
            'endpoint' => '/api/transcribe',
            'http_method' => 'POST',
            'status' => 'provider_succeeded',
            'severity' => 'low',
            'http_status' => 200,
            'provider' => 'groq_transcription',
            'model' => 'model-'.$index,
            'request_summary' => ['fallback_position' => 1],
            'created_at' => $now->copy()->subSeconds($index),
            'updated_at' => $now->copy()->subSeconds($index),
        ]);
    }

    TranscriptionApiRequestLog::query()->create([
        'request_id' => (string) Str::uuid(),
        'operation' => 'summarize_provider',
        'endpoint' => '/internal/summarize',
        'http_method' => 'INTERNAL',
        'status' => 'provider_succeeded',
        'severity' => 'low',
        'http_status' => 200,
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'request_summary' => ['fallback_position' => 1],
    ]);

    $this->actingAs($manager)
        ->getJson(route('api.transcription-providers.logs', [
            'category' => 'transcriber',
            'page' => 2,
            'per_page' => 25,
        ]))
        ->assertOk()
        ->assertJsonCount(25, 'logs')
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.last_page', 3)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.total', 60)
        ->assertJsonPath('pagination.from', 26)
        ->assertJsonPath('pagination.to', 50);

    $this->actingAs($manager)
        ->getJson(route('api.transcription-providers.logs', [
            'category' => 'text_fixer',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'logs')
        ->assertJsonPath('logs.0.source', 'Summarization')
        ->assertJsonPath('logs.0.status', 'provider_succeeded')
        ->assertJsonPath('pagination.total', 1);
});

function createProviderActivityLogManager(): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_PROVIDER_ACTIVITY_LOG',
        'position_name' => 'Test Provider Activity Log',
        'assigned_office' => 'web',
        'category' => 'api',
        'description' => 'Provider activity log test manager',
        'is_active' => true,
    ]);

    UserPermissions::query()->create([
        'position_id' => $position->id,
        'permission_name' => 'API-manage_api',
    ]);

    return User::factory()->create(['position_id' => $position->id]);
}
