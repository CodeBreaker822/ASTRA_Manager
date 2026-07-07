<?php

namespace App\Services;

class ServiceUserMessage
{
    public static function audioReadFailed(): string
    {
        return 'The uploaded audio could not be read. Please upload a valid audio file.';
    }

    public static function missingApiKey(string $provider): string
    {
        return $provider.' API key is not configured.';
    }

    public static function providerRejectedKey(string $provider): string
    {
        return $provider.' rejected the configured API key.';
    }

    public static function providerBusy(string $provider): string
    {
        return $provider.' is busy or rate-limited. Please try again shortly.';
    }

    public static function providerUnavailable(string $provider): string
    {
        return $provider.' is temporarily unavailable. Please try again shortly.';
    }

    public static function cannotReachProvider(string $provider): string
    {
        return 'The server could not reach '.$provider.'. Please check the connection and provider settings.';
    }

    public static function unsupportedProviderModel(string $provider): string
    {
        return 'The selected '.$provider.' model is not supported.';
    }

    public static function transcriptionFailed(string $provider): string
    {
        return $provider.' could not transcribe the audio.';
    }

    public static function emptyTranscriptionResponse(string $provider): string
    {
        return $provider.' returned an empty transcription response.';
    }

    public static function emptyCleanerResponse(string $provider = 'Gemini'): string
    {
        return $provider.' returned an empty polishing response.';
    }

    public static function invalidCleanerResponse(string $provider = 'Gemini'): string
    {
        return $provider.' returned an invalid polishing response.';
    }

    public static function cleanerMissingChunks(string $provider = 'Gemini'): string
    {
        return $provider.' did not return every transcript chunk.';
    }

    public static function cleanerFailed(string $provider = 'Gemini'): string
    {
        return $provider.' could not polish the transcript.';
    }
}
