<?php

use App\Models\ApiTranscriptionJob;
use App\Models\Transcript;
use App\Models\TranscriptionProviderSetting;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\TranscriptExportService;
use App\Services\WebAudioChunkerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

test('workspace requires authentication', function () {
    $this->get(route('workspace.index'))
        ->assertRedirect(route('login'));
});

test('verified users can view the workspace', function () {
    $user = User::factory()->create(['plan' => 'free']);

    $this->actingAs($user)
        ->get(route('workspace.index'))
        ->assertOk();
});

test('users can create rename and delete their transcript projects', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspace.store'), ['title' => 'Client intake call'])
        ->assertRedirect();

    $project = TranscriptProject::query()->where('user_id', $user->id)->firstOrFail();

    expect($project->title)->toBe('Client intake call');

    $this->actingAs($user)
        ->put(route('workspace.update', $project), ['title' => 'Updated call'])
        ->assertRedirect();

    expect($project->refresh()->title)->toBe('Updated call');

    $this->actingAs($user)
        ->delete(route('workspace.destroy', $project))
        ->assertRedirect(route('workspace.index'));

    expect(TranscriptProject::query()->whereKey($project->id)->exists())->toBeFalse();
});

test('users cannot manage another users transcript project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $owner->id,
        'title' => 'Private transcript',
    ]);

    $this->actingAs($other)
        ->get(route('workspace.show', $project))
        ->assertNotFound();

    $this->actingAs($other)
        ->put(route('workspace.update', $project), ['title' => 'Nope'])
        ->assertNotFound();

    $this->actingAs($other)
        ->delete(route('workspace.destroy', $project))
        ->assertNotFound();
});

test('web upload queues the local async transcribe api job and finalizes from status polling', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Local async transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Local async upload transcript.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    $upload = $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->createWithContent('clip.wav', wavContent(2)),
            'server_chunk' => true,
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.status', 'queued');

    $transcriptId = $upload->json('transcript.id');
    $apiJob = ApiTranscriptionJob::query()->firstOrFail();

    expect($apiJob->request_payload['mode'])->toBe('queue')
        ->and($apiJob->request_payload['clips'])->toHaveCount(1)
        ->and($apiJob->request_payload['clips'][0]['audio_path'])->not->toBeEmpty()
        ->and($project->transcripts()->whereKey($transcriptId)->firstOrFail()->status)->toBe('queued');

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.duration_seconds', 2)
        ->assertJsonPath('project.transcripts.0.raw_text', 'Local async upload transcript.')
        ->assertJsonPath('project.transcripts.0.sections.0.text', 'Local async upload transcript.');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), config('services.deepgram.listen_url')));
});

test('server chunking does not create a tiny trailing audio clip', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

    $chunker = app(WebAudioChunkerService::class);
    $prepared = $chunker->clipsFromUpload(
        UploadedFile::fake()->createWithContent('near-minute.wav', wavContent(60.5)),
        0,
    );

    try {
        expect($prepared['clips'])->toHaveCount(1)
            ->and($prepared['clips'][0]['clip_start_ms'])->toBe(0)
            ->and($prepared['clips'][0]['clip_end_ms'])->toBeGreaterThan(60000);
    } finally {
        $chunker->cleanup($prepared['cleanup']);
    }
});

test('web upload completes when transcription provider returns no speech text', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Silent transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => '',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    $upload = $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->createWithContent('silence.wav', wavContent(2)),
            'server_chunk' => true,
        ])
        ->assertAccepted();

    expect(ApiTranscriptionJob::query()->firstOrFail()->status)->toBe('queued');

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.id', $upload->json('transcript.id'))
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.raw_text', '')
        ->assertJsonPath('project.transcripts.0.sections.0.text', '');
});

test('workspace summary modal follows the jerva summary design surface', function () {
    $workspace = File::get(resource_path('js/pages/workspace/Index.vue'));

    expect($workspace)
        ->toContain('renderSummaryMarkdown')
        ->toContain('v-html')
        ->toContain('data-summary-export-format')
        ->toContain('summary_source')
        ->toContain('Starting again replaces this')
        ->toContain('No summary has been created')
        ->toContain('for this project.');
});

test('summary exports include jerva project source and summary structure', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Council meeting',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 120,
        'raw_text' => 'Raw meeting transcript.',
        'cleaned_text' => 'Cleaned meeting transcript.',
        'summary_text' => "## Outcome\n- **Approved** the request\nNext step confirmed.",
        'summary_status' => 'complete',
    ]);

    $exports = app(TranscriptExportService::class);
    $txt = $exports->export($transcript, 'txt', 'summary', 'cleaned');

    expect(File::get($txt['path']))
        ->toContain('Council meeting - Summary')
        ->toContain('Project: Council meeting')
        ->toContain('Source: Cleaned transcript')
        ->toContain('- Approved the request')
        ->toContain('Next step confirmed.');

    File::delete($txt['path']);

    expect(File::get(app_path('Services/TranscriptExportService.php')))
        ->toContain('PhpOffice\\PhpSpreadsheet\\Spreadsheet')
        ->toContain('new Xlsx($spreadsheet)');

    if (! class_exists(ZipArchive::class)) {
        return;
    }

    $xlsx = $exports->export($transcript, 'xlsx', 'summary', 'cleaned');
    $workbook = IOFactory::load($xlsx['path']);
    $sheet = $workbook->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Council meeting - Summary')
        ->and($sheet->getCell('A3')->getValue())->toBe('Project')
        ->and($sheet->getCell('B3')->getValue())->toBe('Council meeting')
        ->and($sheet->getCell('A4')->getValue())->toBe('Source')
        ->and($sheet->getCell('B4')->getValue())->toBe('Cleaned transcript')
        ->and($sheet->getCell('A6')->getValue())->toBe('Summary')
        ->and($sheet->getCell('B6')->getValue())->toContain('- Approved the request');

    $workbook->disconnectWorksheets();
    File::delete($xlsx['path']);
});

test('excel exports report missing php spreadsheet extensions clearly', function () {
    if (extension_loaded('zip') && extension_loaded('gd')) {
        $this->markTestSkipped('PHP zip and gd extensions are enabled.');
    }

    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Council meeting',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 120,
        'raw_text' => 'Raw meeting transcript.',
        'summary_text' => 'Meeting summary.',
        'summary_status' => 'complete',
    ]);

    expect(fn () => app(TranscriptExportService::class)->export($transcript, 'xlsx', 'summary'))
        ->toThrow(RuntimeException::class, 'Excel exports require the PHP zip and gd extensions.');
});

function wavContent(int|float $seconds): string
{
    $sampleRate = 16000;
    $channels = 1;
    $bitsPerSample = 16;
    $sampleCount = (int) round($sampleRate * $seconds);
    $data = '';

    for ($index = 0; $index < $sampleCount; $index++) {
        $sample = (int) round(sin(2 * M_PI * 440 * ($index / $sampleRate)) * 8000);
        $data .= pack('v', $sample < 0 ? $sample + 65536 : $sample);
    }

    $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
    $blockAlign = $channels * intdiv($bitsPerSample, 8);

    return 'RIFF'
        .pack('V', 36 + strlen($data))
        .'WAVEfmt '
        .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
        .'data'
        .pack('V', strlen($data))
        .$data;
}

/**
 * @return array<string, mixed>
 */
function deepgramRuntimeMetadata(): array
{
    return [
        'listen_url' => (string) config('services.deepgram.listen_url'),
        'timeout' => 120,
    ];
}

function availableFfmpegBinary(): ?string
{
    $binary = config('services.ffmpeg.binary', 'ffmpeg');
    $binary = is_string($binary) && trim($binary) !== '' ? trim($binary) : 'ffmpeg';

    $process = new Process([$binary, '-version']);
    $process->setTimeout(5);

    try {
        $process->run();
    } catch (Throwable) {
        return null;
    }

    return $process->isSuccessful() ? $binary : null;
}
