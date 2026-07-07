# Transcription API Integration Guide

## Overview
The Transcription API lets standalone systems send audio clips to this web app and receive transcription or polished transcript output through a license-key protected REST API.

Supported operations:
- Check license and provider capability status.
- Transcribe an uploaded audio clip using Deepgram, ElevenLabs, Speechmatics, or Groq.
- Polish raw transcript text or transcript chunks using the server-managed Text Fixers list.

Base URL:
```text
https://your-domain.com/api
```

Replace `https://your-domain.com` with the production domain of this system.

## Authentication
All endpoints require a license key in the `Authorization` header.

```http
Authorization: Bearer LICENSE_KEY
```

The license key is generated in the system API Manager. The consuming app should store this key securely and send it with every request.

## Provider Summary

| Provider | Purpose | Model |
| --- | --- | --- |
| Deepgram | Speech to text | `nova-3` |
| ElevenLabs | Speech to text | `scribe_v2` |
| Speechmatics | Speech to text | `melia-1` or `enhanced` |
| Groq | Speech to text | `whisper-large-v3` or `whisper-large-v3-turbo` |
| Gemini | Transcript polishing only | `gemini-3.1-flash-lite` |
| Groq | Transcript polishing only | `meta-llama/llama-4-scout-17b-16e-instruct` or `qwen/qwen3-32b` |
| DeepSeek | Transcript polishing only | `deepseek-v4-flash` |
| Cerebras | Transcript polishing only | `gpt-oss-120b` with low reasoning effort |
| Mistral AI | Transcript polishing only | `mistral-small-2603` |
| OpenRouter | Transcript polishing only | `google/gemma-3-12b-it:free` |
| Cloudflare Workers AI | Transcript polishing only | `@cf/zai-org/glm-4.7-flash` |

These are internal server integrations. Consuming apps receive only the virtual provider `AIMS Server` with model `Free-Model-Fast`.

Do not hardcode provider language options in consuming apps. Call `GET /api/license/status` and use the returned `providers.transcription[].models[].languages` list. Text-fixer providers do not return transcription language lists because they are only used for polishing.

## Provider Fallback Protocol

Administrators arrange providers by dragging their rows in **Settings > API Settings**. The server always tries connected providers from top to bottom within the relevant Transcribers or Text Fixers list.

- The top connected provider is primary; each row below it is the next fallback.
- A provider can be added more than once when each row uses a different model. Each provider-model row is an independent fallback step, so Groq can retry with `whisper-large-v3-turbo` after `whisper-large-v3`, for example.
- The same provider and model combination cannot be added twice.
- When another model is added for an existing provider, leaving the API-key field blank reuses that provider's stored encrypted key. Entering a value assigns the replacement key to the new provider-model row. The existing key is shown only as a masked placeholder and is never returned to the browser.
- Disabled or unconfigured providers are skipped without making a request.
- Provider authentication, quota, rate-limit, connection, and processing failures are handled by the server before it tries the next provider.
- Client responses identify only `AIMS Server` and `Free-Model-Fast`; the actual provider and model remain in server logs.
- The API returns a generic `503` only when every connected provider in the list fails.
- Legacy `provider` and `model` request fields are accepted as strings but ignored; they never override the administrator's fallback order.

When an administrator opens or refreshes **Settings > API Settings**, the page runs a new read-only health check for every enabled provider-model row. The status badge reports whether the provider and configured model are online, unavailable, rate limited, or rejecting authentication. Disabled rows are not contacted. These checks use provider metadata or account endpoints and do not upload audio or generate polished text. Deepgram uses its authenticated projects endpoint and ElevenLabs uses its authenticated user endpoint because their general model-catalog responses do not reliably represent Nova and Scribe transcription availability.

## Rate Limit
The API allows up to `120` transcription API requests per minute per license key.

When the license is rate-limited, the API returns:

```json
{
  "message": "License key is rate-limited.",
  "retry_after": 30
}
```

HTTP status: `429`

## Check License and Capabilities

```http
GET /api/license/status
Authorization: Bearer LICENSE_KEY
```

Use this endpoint before starting a transcription session. It tells the client whether the key is valid, whether the key can use the API, which providers are connected, and which language codes are available per provider model.

Example response:

```json
{
  "valid": true,
  "active": true,
  "expired": false,
  "rate_limited": false,
  "app_name": "Standalone Transcriber",
  "version": "1.2.0",
  "zipfile": "standalone-transcriber-1.2.0.zip",
  "rate_limit": {
    "limit_per_minute": 120,
    "retry_after": 0
  },
  "allowed_methods": {
    "post": true,
    "get": true,
    "put": false,
    "patch": false,
    "delete": false
  },
  "apis": {
    "license_status": {
      "method": "GET",
      "path": "/api/license/status",
      "allowed": true
    },
    "transcribe": {
      "method": "POST",
      "path": "/api/transcribe",
      "allowed": true,
      "providers": ["aims_server"],
      "fields": ["audio", "language_code", "clip_index", "clip_start_ms", "clip_end_ms"]
    },
    "polish": {
      "method": "POST",
      "path": "/api/polish",
      "allowed": true,
      "provider": "aims_server",
      "model": "Free-Model-Fast",
      "providers": ["aims_server"],
      "fields": ["text", "timestamps", "chunks", "instruction", "task"]
    },
    "transcriber_update": {
      "method": "GET",
      "path": "/transcriber/standalone-transcriber-1.2.0.zip",
      "allowed": true,
      "zipfile": "standalone-transcriber-1.2.0.zip"
    }
  },
  "providers": {
    "transcription": [
      {
        "provider": "aims_server",
        "name": "AIMS Server",
        "purpose": "Server-managed speech to text",
        "configured": true,
        "enabled": true,
        "connected": true,
        "model": "Free-Model-Fast",
        "models": [
          {
            "id": "Free-Model-Fast",
            "label": "Free-Model-Fast",
            "language_code_parameter": "language_code",
            "default_language_code": "multi",
            "language_code_required": false,
            "accepts_custom_language_code": false,
            "languages": [
              { "code": "multi", "label": "Multilingual" },
              { "code": "en", "label": "English" },
              { "code": "tl", "label": "Tagalog" }
            ]
          }
        ]
      }
    ],
    "polishing": [
      {
        "provider": "aims_server",
        "name": "AIMS Server",
        "purpose": "Server-managed transcript polishing",
        "configured": true,
        "enabled": true,
        "connected": true,
        "model": "Free-Model-Fast",
        "models": [
          {
            "id": "Free-Model-Fast",
            "label": "Free-Model-Fast"
          }
        ]
      }
    ]
  }
}
```

Notes:
- `version` is read from the server's private `storage/app/private/transcriber/version.json` file.
- `zipfile` is the name of the ZIP file published from the Transcriber App Package settings. Both values are `null` when their corresponding artifact is unavailable.
- `apis.transcriber_update.path` is the published ZIP path and already includes the URL-encoded `zipfile` value. It is `null` when no ZIP is available.
- `apis.transcriber_update.allowed` must be `true` before requesting the update ZIP.
- `providers.transcription[].connected` should be `true` before the consuming app offers that provider.
- `apis.transcribe.allowed` must be `true` before sending audio clips.
- `apis.polish.allowed` must be `true` before sending transcript text to a connected polishing provider.
- The `languages` array contains the selectable presets for that provider model. When `accepts_custom_language_code` is `true`, the client may also accept a provider-supported code outside those presets.

## Download Transcriber Update

```http
GET /transcriber/standalone-transcriber-1.2.0.zip
Authorization: Bearer LICENSE_KEY
```

Use the exact `apis.transcriber_update.path` returned by the license status response rather than constructing or hardcoding the filename. Administrators publish the ZIP and its version from **Settings > API Settings > Transcriber App Package**. The upload replaces the previously published ZIP and writes the package metadata under private Laravel storage. The public REST API does not provide upload, edit, or delete operations for this directory.

The download path is served by Laravel rather than as a public static file. Every download requires the same active Bearer license with GET permission. The license status must report `apis.transcriber_update.allowed` as `true` before the client downloads the published ZIP. If the ZIP is unavailable or the license cannot use GET requests, `path` is `null`.

The legacy license-protected download endpoint remains available at `GET /api/transcribe/update/zipfile` for clients that send the Bearer license key with the download request.

## Transcribe Audio

```http
POST /api/transcribe
Authorization: Bearer LICENSE_KEY
Content-Type: multipart/form-data
```

Fields:

| Field | Required | Type | Description |
| --- | --- | --- | --- |
| `audio` | Yes | File | Audio clip file. Maximum upload size is 500 MB. |
| `language_code` | No | String | Language code selected from the single AIMS Server model returned by `GET /api/license/status`. |
| `clip_index` | No | Integer | Zero-based or one-based clip number from the client app. |
| `clip_start_ms` | No | Integer | Clip start time in milliseconds. |
| `clip_end_ms` | No | Integer | Clip end time in milliseconds. |

Example request:

```bash
curl -X POST "https://your-domain.com/api/transcribe" \
  -H "Authorization: Bearer LICENSE_KEY" \
  -F "audio=@chunk_00007.wav" \
  -F "language_code=multi" \
  -F "clip_index=7" \
  -F "clip_start_ms=360000" \
  -F "clip_end_ms=420000"
```

Example response:

```json
{
  "text": "transcribed text here",
  "timestamps": [],
  "provider": "aims_server",
  "provider_name": "AIMS Server",
  "model": "Free-Model-Fast",
  "fallback": {
    "used": false
  },
  "clip_index": 7,
  "clip_start_ms": 360000,
  "clip_end_ms": 420000
}
```

### Language Selection

Use only the language presets returned under the single AIMS Server transcription model. The server translates the selected language setting for whichever internal provider handles the request.

## Polish Transcript

```http
POST /api/polish
Authorization: Bearer LICENSE_KEY
Content-Type: application/json
```

Send either a single transcript in `text` or multiple transcript clips in `chunks`.

### Polish Single Transcript

Request:

```json
{
  "text": "raw transcript text here",
  "timestamps": [],
  "instruction": "Clean the transcript, fix punctuation, and preserve the original meaning.",
  "task": "polish"
}
```

Response:

```json
{
  "text": "polished transcript text here",
  "timestamps": [],
  "provider": "aims_server",
  "provider_name": "AIMS Server",
  "model": "Free-Model-Fast",
  "fallback": {
    "used": true
  }
}
```

### Polish Transcript Chunks

Request:

```json
{
  "chunks": [
    {
      "audio_chunk_id": 7,
      "clip_index": 7,
      "range_label": "06:00 - 07:00",
      "text": "raw transcript text here",
      "timestamps": []
    }
  ],
  "instruction": "Clean punctuation and grammar while keeping names and numbers unchanged."
}
```

Response:

```json
{
  "chunks": [
    {
      "audio_chunk_id": 7,
      "text": "polished transcript text here",
      "timestamps": []
    }
  ],
  "provider": "aims_server",
  "provider_name": "AIMS Server",
  "model": "Free-Model-Fast",
  "fallback": {
    "used": true
  }
}
```

Rules:
- Send `text` or `chunks`.
- `task` is optional and accepts `polish` or `summarize`. Send `summarize` when requesting a summary so large transcripts use section summaries followed by one final combined summary.
- Do not send a provider or model. The server uses the administrator's Text Fixers fallback order.
- If both are empty, the API returns `422`.
- Large transcripts and chunk collections are divided into bounded requests. Large summary sections are assigned round-robin across the connected Text Fixers so the work is shared between providers.
- Each summary section has its own circular fallback order. If its assigned provider fails, the server tries every provider that has not yet processed that section, wrapping from the bottom of the configured list back to the top when necessary.
- After every section summary succeeds, the combined material is sent to the top-priority Text Fixer for the final summary. If the top provider fails, the final pass falls back through the remaining configured providers.
- Empty, malformed, incomplete, or token-truncated provider output is never returned as a successful response. The server retries unusable HTTP `200` output up to three total attempts before continuing to the next fallback provider.
- Temporary connection, rate-limit, and server failures use up to three total HTTP attempts. Authentication, unsupported-model, oversized-input, and explicit credit or quota exhaustion errors move directly to fallback.
- Gemini, Groq text-fixer models, DeepSeek, Cerebras, Mistral, OpenRouter, and Cloudflare Workers AI are only used for polishing, summarization, and chatbot responses—not direct audio transcription.
- Cloudflare requires both a Workers AI API token and the Cloudflare Account ID. API Settings stores the Account ID as non-secret metadata on that provider row.

Groq model note: the configured text-fixer models are the requested `meta-llama/llama-4-scout-17b-16e-instruct` and `qwen/qwen3-32b`. Groq has announced a July 17, 2026 shutdown for these models on free and developer tiers, so their configured IDs will need to be replaced before that date unless the account has an applicable enterprise commitment.

## Error Responses

Invalid or missing license:

```json
{
  "message": "Missing Bearer license key."
}
```

HTTP status: `401`

Inactive license:

```json
{
  "message": "License key is inactive."
}
```

HTTP status: `403`

Method not allowed for license:

```json
{
  "message": "License key cannot use POST requests."
}
```

HTTP status: `403`

Validation error:

```json
{
  "message": "The audio field is required.",
  "errors": {
    "audio": ["The audio field is required."]
  }
}
```

HTTP status: `422`

Provider error:

```json
{
  "message": "Deepgram rejected the configured API key. Please check the API key in settings."
}
```

HTTP status depends on the provider response.

## Recommended Client Flow

1. Call `GET /api/license/status`.
2. If `valid`, `active`, and `apis.transcribe.allowed` are true, load available providers from `providers.transcription`.
3. Show only providers where `connected` is true.
4. Show the language list from the selected provider model.
5. Upload audio clips one at a time to `POST /api/transcribe`.
6. Store the returned `text`, `timestamps`, provider, model, and clip metadata.
7. Send the raw text or chunks to `POST /api/polish` if cleaned transcript output is needed.
8. If a request returns `429`, wait for `retry_after` seconds before retrying.

## JavaScript Example

```js
const baseUrl = "https://your-domain.com/api";
const licenseKey = "LICENSE_KEY";

async function getStatus() {
  const response = await fetch(`${baseUrl}/license/status`, {
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
    },
  });

  return response.json();
}

async function transcribeClip(file, languageCode, clip) {
  const form = new FormData();
  form.append("audio", file);
  form.append("language_code", languageCode);
  form.append("clip_index", clip.index);
  form.append("clip_start_ms", clip.startMs);
  form.append("clip_end_ms", clip.endMs);

  const response = await fetch(`${baseUrl}/transcribe`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
    },
    body: form,
  });

  return response.json();
}

async function polishTranscript(text, timestamps = []) {
  const response = await fetch(`${baseUrl}/polish`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      text,
      timestamps,
      instruction: "Clean punctuation and grammar while preserving the meaning.",
    }),
  });

  return response.json();
}
```

## Production Setup Notes

Before other systems can connect:

1. Run production migrations.
2. Generate a license key in API Manager.
3. Enable `POST` access for that license key.
4. Add each required provider and model combination in API Manager. The same provider may be added multiple times with different models.
5. Keep only the providers that should be usable enabled.
6. Drag provider rows into the required primary and fallback order.
7. Share only the license key and API base URL with consuming systems.

Never expose Deepgram, ElevenLabs, Speechmatics, Gemini, Groq, DeepSeek, Cerebras, Mistral, OpenRouter, or Cloudflare provider API keys to client apps. Client apps should only know this system's license key.
