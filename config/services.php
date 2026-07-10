<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
        'token' => env('POSTMARK_TOKEN', env('POSTMARK_API_KEY')),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY', env('RESEND_KEY')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'lambda_pdf' => [
        'url' => env('LAMBDA_PDF_URL'),
    ],

    'aws' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
    ],

    'deepgram' => [
        'key' => env('DEEPGRAM_API_KEY'),
        'listen_url' => env('DEEPGRAM_LISTEN_URL', 'https://api.deepgram.com/v1/listen'),
        'models_url' => env('DEEPGRAM_MODELS_URL', 'https://api.deepgram.com/v1/models'),
        'projects_url' => env('DEEPGRAM_PROJECTS_URL', 'https://api.deepgram.com/v1/projects'),
        'model' => 'nova-3',
        'language' => env('DEEPGRAM_LANGUAGE', 'multi'),
        'timeout' => env('DEEPGRAM_TIMEOUT', 120),
        'speech_to_text_models' => ['nova-3'],
    ],

    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'speech_to_text_url' => env('ELEVENLABS_SPEECH_TO_TEXT_URL', 'https://api.elevenlabs.io/v1/speech-to-text'),
        'models_url' => env('ELEVENLABS_MODELS_URL', 'https://api.elevenlabs.io/v1/models'),
        'user_url' => env('ELEVENLABS_USER_URL', 'https://api.elevenlabs.io/v1/user'),
        'speech_to_text_model' => 'scribe_v2',
        'speech_to_text_models' => ['scribe_v2'],
        'timeout' => env('ELEVENLABS_TIMEOUT', 120),
    ],

    'speechmatics' => [
        'key' => env('SPEECHMATICS_API_KEY'),
        'base_url' => env('SPEECHMATICS_BASE_URL', 'https://asr.api.speechmatics.com/v2'),
        'model' => 'melia-1',
        'language' => env('SPEECHMATICS_LANGUAGE', 'auto'),
        'timeout' => env('SPEECHMATICS_TIMEOUT', 120),
        'poll_interval_ms' => env('SPEECHMATICS_POLL_INTERVAL_MS', 1000),
        'max_wait_seconds' => env('SPEECHMATICS_MAX_WAIT_SECONDS', 300),
        'speech_to_text_models' => ['melia-1', 'enhanced'],
    ],

    'gladia' => [
        'base_url' => env('GLADIA_BASE_URL', 'https://api.gladia.io/v2'),
        'timeout' => env('GLADIA_TIMEOUT', 120),
        'poll_interval_ms' => env('GLADIA_POLL_INTERVAL_MS', 1000),
        'max_wait_seconds' => env('GLADIA_MAX_WAIT_SECONDS', 300),
    ],

    'assemblyai' => [
        'base_url' => env('ASSEMBLYAI_BASE_URL', 'https://api.assemblyai.com/v2'),
        'timeout' => env('ASSEMBLYAI_TIMEOUT', 120),
        'poll_interval_ms' => env('ASSEMBLYAI_POLL_INTERVAL_MS', 1000),
        'max_wait_seconds' => env('ASSEMBLYAI_MAX_WAIT_SECONDS', 300),
    ],

    'azure_speech' => [
        'fast_transcription_url' => env('AZURE_SPEECH_FAST_TRANSCRIPTION_URL', 'https://%s.api.cognitive.microsoft.com/speechtotext/transcriptions:transcribe?api-version=2025-10-15'),
        'timeout' => env('AZURE_SPEECH_TIMEOUT', 180),
    ],

    'google_speech' => [
        'base_url' => env('GOOGLE_SPEECH_BASE_URL', 'https://speech.googleapis.com'),
        'token_url' => env('GOOGLE_SPEECH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => env('GOOGLE_SPEECH_TIMEOUT', 180),
    ],

    'aws_transcribe' => [
        'poll_interval_ms' => env('AWS_TRANSCRIBE_POLL_INTERVAL_MS', 1000),
        'max_wait_seconds' => env('AWS_TRANSCRIBE_MAX_WAIT_SECONDS', 300),
    ],

    'runpod' => [
        'timeout' => env('RUNPOD_TIMEOUT', 300),
        'audio_url_ttl_seconds' => env('RUNPOD_AUDIO_URL_TTL_SECONDS', 600),
        'beam_size' => env('RUNPOD_BEAM_SIZE', 5),
        'vad_filter' => env('RUNPOD_VAD_FILTER', false),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => 'gemini-3.1-flash-lite',
        'models' => ['gemini-3.1-flash-lite'],
        'timeout' => env('GEMINI_TIMEOUT', 120),
        'max_retries' => env('GEMINI_MAX_RETRIES', 3),
        'rpm_limit' => env('GEMINI_RPM_LIMIT', 15),
        'rate_limit_key' => env('GEMINI_RATE_LIMIT_KEY', 'gemini_global_requests_per_minute'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'transcription_url' => env('GROQ_TRANSCRIPTION_URL', rtrim(env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'), '/').'/audio/transcriptions'),
        'chat_completions_url' => env('GROQ_CHAT_COMPLETIONS_URL', rtrim(env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'), '/').'/chat/completions'),
        'transcription_model' => 'whisper-large-v3',
        'transcription_models' => ['whisper-large-v3', 'whisper-large-v3-turbo'],
        'text_fixer_model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
        'text_fixer_models' => ['meta-llama/llama-4-scout-17b-16e-instruct', 'qwen/qwen3-32b'],
        'timeout' => env('GROQ_TIMEOUT', 120),
        'max_retries' => env('GROQ_MAX_RETRIES', 3),
    ],

    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'chat_completions_url' => env('DEEPSEEK_CHAT_COMPLETIONS_URL', rtrim(env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/').'/chat/completions'),
        'models_url' => env('DEEPSEEK_MODELS_URL', rtrim(env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/').'/models'),
        'model' => 'deepseek-v4-flash',
        'models' => ['deepseek-v4-flash'],
        'timeout' => env('DEEPSEEK_TIMEOUT', 120),
        'max_retries' => env('DEEPSEEK_MAX_RETRIES', 3),
    ],

    'cerebras' => [
        'key' => env('CEREBRAS_API_KEY'),
        'base_url' => env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'),
        'chat_completions_url' => env('CEREBRAS_CHAT_COMPLETIONS_URL', rtrim(env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'), '/').'/chat/completions'),
        'models_url' => env('CEREBRAS_MODELS_URL', rtrim(env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'), '/').'/models'),
        'model' => 'gpt-oss-120b',
        'models' => ['gpt-oss-120b'],
        'timeout' => env('CEREBRAS_TIMEOUT', 120),
        'max_retries' => env('CEREBRAS_MAX_RETRIES', 3),
    ],

    'mistral' => [
        'key' => env('MISTRAL_API_KEY'),
        'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        'chat_completions_url' => env('MISTRAL_CHAT_COMPLETIONS_URL', rtrim(env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'), '/').'/chat/completions'),
        'models_url' => env('MISTRAL_MODELS_URL', rtrim(env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'), '/').'/models'),
        'model' => 'mistral-small-2603',
        'models' => ['mistral-small-2603'],
        'timeout' => env('MISTRAL_TIMEOUT', 120),
        'max_retries' => env('MISTRAL_MAX_RETRIES', 3),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'chat_completions_url' => env('OPENROUTER_CHAT_COMPLETIONS_URL', rtrim(env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/').'/chat/completions'),
        'models_url' => env('OPENROUTER_MODELS_URL', rtrim(env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/').'/models'),
        'model' => 'google/gemma-3-12b-it:free',
        'models' => ['google/gemma-3-12b-it:free'],
        'timeout' => env('OPENROUTER_TIMEOUT', 120),
        'max_retries' => env('OPENROUTER_MAX_RETRIES', 3),
    ],

    'cloudflare' => [
        'key' => env('CLOUDFLARE_AI_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'base_url' => env('CLOUDFLARE_AI_BASE_URL', 'https://api.cloudflare.com/client/v4/accounts'),
        'model' => '@cf/zai-org/glm-4.7-flash',
        'models' => ['@cf/zai-org/glm-4.7-flash'],
        'timeout' => env('CLOUDFLARE_AI_TIMEOUT', 120),
        'max_retries' => env('CLOUDFLARE_AI_MAX_RETRIES', 3),
    ],

    'transcript_polishing' => [
        'response_attempts' => env('TRANSCRIPT_POLISH_RESPONSE_ATTEMPTS', 3),
        'chunk_characters' => env('TRANSCRIPT_POLISH_CHUNK_CHARACTERS', 16000),
        'max_output_tokens' => env('TRANSCRIPT_POLISH_MAX_OUTPUT_TOKENS', 4096),
    ],

    'transcription_processing' => [
        'response_attempts' => env('TRANSCRIPTION_RESPONSE_ATTEMPTS', 3),
    ],

    'provider_health' => [
        'connect_timeout' => env('PROVIDER_HEALTH_CONNECT_TIMEOUT', 3),
        'timeout' => env('PROVIDER_HEALTH_TIMEOUT', 6),
    ],

];
