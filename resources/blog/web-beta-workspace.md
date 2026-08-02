---
title: What the JERVA Web Transcription Workspace Supports Today
slug: web-beta-workspace
date: 2026-07-26
excerpt: A transparent product guide to JERVA's current browser transcription workflow, including projects, long uploads, live audio, review, online polish, summaries, exports, and its limits.
---

The JERVA web workspace is the browser-based route for turning uploaded or live audio into text through hosted services. It is not the same as running a Whisper model inside the browser, and it does not claim to replace careful transcript review.

This page describes the current product boundary so you can choose the right workflow.

## What the browser workspace does

After creating an account, you can create transcript projects and add an existing recording or capture live microphone audio. The web application prepares the source, sends the required audio through the server transcription pipeline, and reports progress as transcript sections become available.

The upload control accepts common audio formats including WAV, MP3, M4A, AAC, OGG, FLAC, and WebM. A valid extension does not guarantee that every damaged or unusual codec can be decoded.

Long uploads use a chunked source-upload workflow so the browser does not have to send a large file in one fragile request. The server then prepares transcription sections and tracks one transcript job for that source.

## Projects and long-audio progress

Projects keep related transcript jobs organized. During processing, the workspace shows job state, section progress, and available logs. Retry and cancellation behavior are designed to make a long recording recoverable when a request or provider fails.

Processing time is not fixed. It depends on source duration, file size, upload connection, queue load, configured provider, fallback behavior, and service availability.

## Review, polish, summarize, and export

The transcript remains connected to structured sections and timing information. Use source playback where available to check names, numbers, terminology, decisions, and quotations.

JERVA can send transcript text to configured online tools for polishing or summaries. These are separate from speech recognition:

- **Polish** aims to improve readability and correct obvious transcript issues.
- **Summary** produces a shorter interpretation of the transcript.

Neither output is guaranteed to preserve every detail. Keep the raw transcript and review generated changes.

Exports are available as TXT, Word-compatible, and Excel-compatible files. Choose raw or cleaned text according to the task and retain both when traceability matters.

## Free allowance and hosted use

The web workspace uses account-based hosted processing. Accounts receive the free daily transcription allowance currently configured in the pricing system. Additional use follows the current pay-as-you-go rates.

JERVA does not require a monthly or yearly subscription under the current pricing model. Check the [pricing page](/price) for the actual allowance and rates rather than relying on an old article or screenshot.

## What the browser workspace does not do

- It does not run the desktop Whisper models inside the browser.
- It does not promise one accuracy level for every provider, language, and recording.
- It does not know the real identity of a detected speaker automatically.
- It does not turn a generated summary into approved minutes or a certified transcript.
- It does not remove the need to follow consent, privacy, and retention rules.

## When to use the Windows app instead

Use the free Windows app when you need supported local Whisper transcription, local Silero voice activity detection, local speaker diarization, or an offline-capable workflow after setup.

The Windows app can also connect to hosted services when online mode is selected. Read [online versus offline audio transcription](/blog/web-vs-desktop) for the full comparison.

To begin, explore the [JERVA audio-to-text workflow](/audio-to-text) or [create an online account](/register).
