<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

test('download latest streams the desktop package publicly', function () {
    Storage::fake('local');

    $directory = Storage::disk('local')->path('transcriber');
    File::ensureDirectoryExists($directory);
    File::put($directory.DIRECTORY_SEPARATOR.'standalone-transcriber.zip', 'fake zip');

    $this->get(route('download.latest'))
        ->assertOk()
        ->assertDownload('standalone-transcriber.zip');
});

test('old license gated updater endpoints are gone', function () {
    $this->getJson('/api/transcribe/update/zipfile')->assertNotFound();
    $this->get('/transcriber/standalone-transcriber.zip')->assertNotFound();
});
