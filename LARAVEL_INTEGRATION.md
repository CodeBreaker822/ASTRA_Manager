# Laravel Integration for Cloudflare AI Pipeline Worker

This guide connects a Laravel 13 application to the deployed Cloudflare AI Worker.

The Laravel application is responsible for choosing the model, loading translation and summarization instructions, validating users, charging credits, and storing results. The Worker is responsible for authenticating the Laravel server, validating Worker request fields, and running Cloudflare Workers AI.

## Request flow

```text
Browser or desktop app
        ↓
Laravel API
        ↓ Bearer token
Cloudflare AI Worker
        ↓ AI binding
Cloudflare Workers AI
```

Never send the Worker bearer token to the browser or desktop client. Only Laravel should know it.

## Worker endpoints

| Endpoint | Method | Body |
|---|---:|---|
| `/models` | GET | None |
| `/transcribe` | POST | Raw audio binary |
| `/translate` | POST | JSON |
| `/summarize` | POST | JSON |
| `/pipeline` | POST | `multipart/form-data` |

All endpoints require:

```http
Authorization: Bearer YOUR_WORKER_AUTH_TOKEN
```

## Recommended Laravel files

```text
app/
├── Exceptions/
│   └── CloudflareAiException.php
└── Services/
    └── AI/
        └── CloudflareWorkersAiService.php

config/
└── services.php

.env
```

The instructions are not stored inside the Worker or the Laravel service class. The calling controller, job, action, or instruction repository supplies them to the service methods.

## Environment variables

Add these values to the Laravel `.env` file:

```env
CLOUDFLARE_WORKERS_AI_URL=https://your-worker.your-subdomain.workers.dev
CLOUDFLARE_WORKERS_AI_TOKEN=the-same-value-as-the-worker-auth-token
CLOUDFLARE_WORKERS_AI_TIMEOUT=300
CLOUDFLARE_WORKERS_AI_CONNECT_TIMEOUT=10
```

Do not add a trailing slash to the URL.

## Laravel service configuration

Add this entry inside the returned array in `config/services.php`:

```php
'cloudflare_workers_ai' => [
    'url' => env('CLOUDFLARE_WORKERS_AI_URL'),
    'token' => env('CLOUDFLARE_WORKERS_AI_TOKEN'),
    'timeout' => (int) env('CLOUDFLARE_WORKERS_AI_TIMEOUT', 300),
    'connect_timeout' => (int) env('CLOUDFLARE_WORKERS_AI_CONNECT_TIMEOUT', 10),
],
```

## Custom exception

Create `app/Exceptions/CloudflareAiException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class CloudflareAiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $requestId = null,
        public readonly ?string $workerCode = null,
        public readonly mixed $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
```

## Worker service

Create `app/Services/AI/CloudflareWorkersAiService.php`:

```php
<?php

namespace App\Services\AI;

use App\Exceptions\CloudflareAiException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use LogicException;
use RuntimeException;

final class CloudflareWorkersAiService
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.cloudflare_workers_ai.url'), '/');
        $this->token = (string) config('services.cloudflare_workers_ai.token');
        $this->timeout = (int) config('services.cloudflare_workers_ai.timeout', 300);
        $this->connectTimeout = (int) config('services.cloudflare_workers_ai.connect_timeout', 10);

        if ($this->baseUrl === '') {
            throw new LogicException('CLOUDFLARE_WORKERS_AI_URL is not configured.');
        }

        if ($this->token === '') {
            throw new LogicException('CLOUDFLARE_WORKERS_AI_TOKEN is not configured.');
        }
    }

    public function models(): array
    {
        return $this->decode(
            $this->client()->get('/models')
        );
    }

    public function translate(
        string $text,
        string $instruction,
        ?string $model = null,
        array $options = [],
    ): array {
        return $this->textTask(
            endpoint: '/translate',
            text: $text,
            instruction: $instruction,
            model: $model,
            options: $options,
        );
    }

    public function summarize(
        string $text,
        string $instruction,
        ?string $model = null,
        array $options = [],
    ): array {
        return $this->textTask(
            endpoint: '/summarize',
            text: $text,
            instruction: $instruction,
            model: $model,
            options: $options,
        );
    }

    public function transcribe(
        string $audioPath,
        string $mimeType,
        ?string $model = null,
        array $options = [],
    ): array {
        $resource = $this->openAudio($audioPath);
        $stream = Utils::streamFor($resource);
        $headers = [];

        if ($model !== null && $model !== '') {
            $headers['X-Transcription-Model'] = $model;
        }

        if (isset($options['task'])) {
            $headers['X-Transcription-Task'] = (string) $options['task'];
        }

        if (isset($options['language'])) {
            $headers['X-Transcription-Language'] = (string) $options['language'];
        }

        if (array_key_exists('vad_filter', $options)) {
            $headers['X-VAD-Filter'] = $options['vad_filter'] ? 'true' : 'false';
        }

        if (isset($options['initial_prompt'])) {
            $headers['X-Initial-Prompt'] = (string) $options['initial_prompt'];
        }

        try {
            $response = $this->client()
                ->withHeaders($headers)
                ->withBody($stream, $mimeType)
                ->post('/transcribe');

            return $this->decode($response);
        } finally {
            $stream->close();
        }
    }

    public function pipeline(
        string $audioPath,
        string $mimeType,
        array $transcription = [],
        ?array $translation = null,
        ?array $summarization = null,
    ): array {
        $resource = $this->openAudio($audioPath);
        $form = [];

        if (! empty($transcription['model'])) {
            $form['transcription_model'] = (string) $transcription['model'];
        }

        if (! empty($transcription['options'])) {
            $form['transcription_options'] = $this->encodeJson($transcription['options']);
        }

        $this->appendTextTask($form, 'translation', $translation);
        $this->appendTextTask($form, 'summarization', $summarization);

        try {
            $response = $this->client()
                ->attach(
                    'audio',
                    $resource,
                    basename($audioPath),
                    ['Content-Type' => $mimeType],
                )
                ->post('/pipeline', $form);

            return $this->decode($response);
        } finally {
            fclose($resource);
        }
    }

    private function textTask(
        string $endpoint,
        string $text,
        string $instruction,
        ?string $model,
        array $options,
    ): array {
        $payload = [
            'text' => $text,
            'instruction' => $instruction,
        ];

        if ($model !== null && $model !== '') {
            $payload['model'] = $model;
        }

        if ($options !== []) {
            $payload['options'] = $options;
        }

        return $this->decode(
            $this->client()->post($endpoint, $payload)
        );
    }

    private function appendTextTask(
        array &$form,
        string $prefix,
        ?array $task,
    ): void {
        if ($task === null) {
            return;
        }

        $instruction = trim((string) ($task['instruction'] ?? ''));

        if ($instruction === '') {
            throw new LogicException("{$prefix}.instruction is required.");
        }

        $form["{$prefix}_instruction"] = $instruction;

        if (! empty($task['model'])) {
            $form["{$prefix}_model"] = (string) $task['model'];
        }

        if (! empty($task['options'])) {
            $form["{$prefix}_options"] = $this->encodeJson($task['options']);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    private function openAudio(string $audioPath)
    {
        if (! is_file($audioPath) || ! is_readable($audioPath)) {
            throw new RuntimeException("Audio file is not readable: {$audioPath}");
        }

        $resource = fopen($audioPath, 'rb');

        if ($resource === false) {
            throw new RuntimeException("Unable to open audio file: {$audioPath}");
        }

        return $resource;
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('Unable to encode Worker request options.', previous: $exception);
        }
    }

    private function decode(Response $response): array
    {
        try {
            $data = $response->throw()->json();
        } catch (RequestException $exception) {
            $payload = $exception->response->json();

            throw new CloudflareAiException(
                message: (string) data_get($payload, 'error.message', $exception->getMessage()),
                status: $exception->response->status(),
                requestId: data_get($payload, 'request_id')
                    ?? $exception->response->header('X-Request-ID'),
                workerCode: data_get($payload, 'error.code'),
                details: data_get($payload, 'error.details'),
                previous: $exception,
            );
        }

        if (! is_array($data)) {
            throw new CloudflareAiException(
                message: 'The Worker returned an invalid JSON response.',
                status: $response->status(),
                requestId: $response->header('X-Request-ID'),
            );
        }

        return $data;
    }
}
```

## Check the Laravel connection in a browser

Add this temporary route to `routes/web.php`:

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;
use Illuminate\Support\Facades\Route;

Route::get('/debug/cloudflare-ai', function (CloudflareWorkersAiService $ai) {
    return response()->json($ai->models());
});
```

Open this URL in the browser:

```text
https://your-laravel-domain.com/debug/cloudflare-ai
```

A successful response contains the Worker model aliases and defaults:

```json
{
  "request_id": "0d559558-104f-47c1-b24e-c31df70f05a0",
  "transcription": {
    "whisper": "@cf/openai/whisper",
    "whisper-turbo": "@cf/openai/whisper-large-v3-turbo",
    "whisper-tiny-en": "@cf/openai/whisper-tiny-en"
  },
  "llm": {
    "llama-3.2-1b": "@cf/meta/llama-3.2-1b-instruct",
    "llama-3.2-3b": "@cf/meta/llama-3.2-3b-instruct",
    "llama-3.1-8b-fast": "@cf/meta/llama-3.1-8b-instruct-fp8-fast",
    "llama-3.1-70b-fast": "@cf/meta/llama-3.1-70b-instruct-fp8-fast",
    "llama-3.3-70b-fast": "@cf/meta/llama-3.3-70b-instruct-fp8-fast",
    "glm-4.7-flash": "@cf/zai-org/glm-4.7-flash"
  },
  "defaults": {
    "transcription": "whisper-turbo",
    "llm": "glm-4.7-flash"
  }
}
```

Remove or protect the debug route after testing.

## Translation usage

The Laravel code supplies the complete instruction. The service does not construct or modify it.

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;

$instruction = $translationInstructionFromDatabase;

$result = app(CloudflareWorkersAiService::class)->translate(
    text: $transcript,
    instruction: $instruction,
    model: 'glm-4.7-flash',
    options: [
        'max_tokens' => 4096,
        'temperature' => 0.1,
    ],
);

$translation = $result['translation'];
$requestId = $result['request_id'];
```

The JSON sent by Laravel is:

```json
{
  "text": "Transcript text",
  "instruction": "Instruction loaded by Laravel",
  "model": "glm-4.7-flash",
  "options": {
    "max_tokens": 4096,
    "temperature": 0.1
  }
}
```

## Summarization usage

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;

$instruction = $summarizationInstructionFromDatabase;

$result = app(CloudflareWorkersAiService::class)->summarize(
    text: $transcript,
    instruction: $instruction,
    model: 'glm-4.7-flash',
    options: [
        'max_tokens' => 2048,
        'temperature' => 0.1,
    ],
);

$summary = $result['summary'];
$requestId = $result['request_id'];
```

## Direct transcription usage

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;

$result = app(CloudflareWorkersAiService::class)->transcribe(
    audioPath: $audioPath,
    mimeType: $mimeType,
    model: 'whisper-turbo',
    options: [
        'task' => 'transcribe',
        'language' => 'en',
        'vad_filter' => true,
        'initial_prompt' => 'Technical meeting involving Laravel and Cloudflare.',
    ],
);

$transcript = $result['transcript'];
$requestId = $result['request_id'];
```

The direct `/transcribe` endpoint currently accepts these options:

```text
task
language
vad_filter
initial_prompt
```

Use `/pipeline` when sending additional transcription options such as `beam_size`, `condition_on_previous_text`, or threshold values.

## Complete pipeline usage

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;

$result = app(CloudflareWorkersAiService::class)->pipeline(
    audioPath: $audioPath,
    mimeType: $mimeType,
    transcription: [
        'model' => 'whisper-turbo',
        'options' => [
            'task' => 'transcribe',
            'language' => 'en',
            'vad_filter' => true,
            'condition_on_previous_text' => false,
            'beam_size' => 5,
        ],
    ],
    translation: [
        'model' => 'glm-4.7-flash',
        'instruction' => $translationInstructionFromDatabase,
        'options' => [
            'max_tokens' => 4096,
            'temperature' => 0.1,
        ],
    ],
    summarization: [
        'model' => 'glm-4.7-flash',
        'instruction' => $summarizationInstructionFromDatabase,
        'options' => [
            'max_tokens' => 2048,
            'temperature' => 0.1,
        ],
    ],
);

$transcript = $result['transcript'];
$translation = $result['translation'] ?? null;
$summary = $result['summary'] ?? null;
$requestId = $result['request_id'];
```

Translation and summarization are optional. Pass `null` to skip either task:

```php
$result = app(CloudflareWorkersAiService::class)->pipeline(
    audioPath: $audioPath,
    mimeType: $mimeType,
    transcription: [
        'model' => 'whisper-turbo',
        'options' => [
            'task' => 'transcribe',
            'vad_filter' => true,
        ],
    ],
    translation: null,
    summarization: [
        'model' => 'glm-4.7-flash',
        'instruction' => $summarizationInstructionFromDatabase,
        'options' => [
            'max_tokens' => 2048,
            'temperature' => 0.1,
        ],
    ],
);
```

## Using a Laravel uploaded file

```php
<?php

use App\Services\AI\CloudflareWorkersAiService;
use Illuminate\Http\Request;

public function process(Request $request, CloudflareWorkersAiService $ai)
{
    $validated = $request->validate([
        'audio' => ['required', 'file'],
    ]);

    $audio = $request->file('audio');
    $mimeType = $audio->getMimeType() ?: 'application/octet-stream';

    $result = $ai->pipeline(
        audioPath: $audio->path(),
        mimeType: $mimeType,
        transcription: [
            'model' => 'whisper-turbo',
            'options' => [
                'task' => 'transcribe',
                'vad_filter' => true,
            ],
        ],
        translation: null,
        summarization: null,
    );

    return response()->json($result);
}
```

## Worker error response

The Worker returns errors in this shape:

```json
{
  "request_id": "0d559558-104f-47c1-b24e-c31df70f05a0",
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "'instruction' must be a non-empty string.",
    "details": null
  }
}
```

The service converts that response into `CloudflareAiException`:

```php
<?php

use App\Exceptions\CloudflareAiException;
use App\Services\AI\CloudflareWorkersAiService;

try {
    $result = app(CloudflareWorkersAiService::class)->summarize(
        text: $transcript,
        instruction: $instruction,
        model: 'glm-4.7-flash',
    );
} catch (CloudflareAiException $exception) {
    report($exception);

    return response()->json([
        'message' => $exception->getMessage(),
        'worker_code' => $exception->workerCode,
        'worker_request_id' => $exception->requestId,
        'details' => $exception->details,
    ], $exception->status);
}
```

Keep the Worker `request_id` in application logs. It lets the Laravel request be matched with Cloudflare Worker logs and AI Gateway metadata.

## Available generation options

The Worker currently validates these translation and summarization options:

| Option | Worker range |
|---|---:|
| `max_tokens` | 1 to 32768 |
| `temperature` | 0 to 2 |
| `top_p` | 0.001 to 1 |
| `top_k` | 1 to 50 |
| `seed` | 1 to 9999999999 |
| `repetition_penalty` | 0 to 2 |
| `frequency_penalty` | -2 to 2 |
| `presence_penalty` | -2 to 2 |

A model may support only a subset of these options. The Worker allowlist prevents Laravel from submitting arbitrary Cloudflare model IDs.

## Instruction ownership

A clean separation is:

```text
Laravel database or settings
        ↓
Instruction repository or application action
        ↓
CloudflareWorkersAiService method argument
        ↓
Worker system message
```

The Worker receives the instruction exactly as Laravel sends it:

```text
System message: instruction
User message: transcript or source text
```

This allows the Laravel dashboard to update translation style, target language, summary structure, speaker preservation rules, and other behavior without redeploying the Worker.

## Production notes

Do not expose `CLOUDFLARE_WORKERS_AI_TOKEN` in JavaScript, API responses, logs, installer configuration, or public repositories.

Do not automatically retry every AI request. A request may have reached the model even when Laravel did not receive the response, so an automatic retry can repeat work and consume credits twice.

Use Laravel queued jobs for long audio processing. Store the Worker `request_id`, selected model aliases, instruction version, user credit transaction, and resulting transcript together so usage can be audited.

The Laravel server-to-server request does not depend on browser CORS. CORS matters only when a browser directly calls the Worker, which is not recommended for this design.
