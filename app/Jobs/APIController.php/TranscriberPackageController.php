<?php

namespace App\Jobs\APIController;

use App\Http\Controllers\Controller;
use App\Services\Api\TranscriberPackageService;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranscriberPackageController extends Controller
{
    public function uploadTranscriberPackage(Request $request, TranscriberPackageService $packages): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z](?:[0-9A-Za-z._+\-]{0,48}[0-9A-Za-z])?$/'],
            'package' => ['required', 'file', 'mimes:zip', 'max:512000'],
        ], [
            'version.regex' => 'The version may only contain letters, numbers, dots, underscores, plus signs, and hyphens.',
            'package.mimes' => 'The Transcriber App Package must be a ZIP file.',
            'package.max' => 'The Transcriber App Package must not exceed 500 MB.',
        ]);

        $version = $validated['version'];

        try {
            $published = $packages->publish($version, $request->file('package'));
        } catch (Throwable $exception) {
            $errorId = (string) Str::uuid();

            Log::error('Transcriber App Package upload failed.', [
                'error_id' => $errorId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return response()->json([
                'message' => $packages->uploadError($exception, $errorId),
                'error_id' => $errorId,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transcriber App Package uploaded successfully!',
            'version' => $published['version'],
            'zipfile' => $published['zipfile'],
        ]);
    }

    public function uploadTranscriberPackageChunk(Request $request, ChunkedUploadService $chunks): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'chunk' => ['required', 'file', 'max:51200'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:200'],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.ChunkedUploadService::MAX_TOTAL_BYTES],
            'filename' => ['required', 'string', 'max:180'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'chunk_hash' => ['nullable', 'string', 'size:64'],
        ]);

        try {
            $payload = $chunks->storeChunk(
                'transcriber-package',
                $this->packageUploadOwnerKey($request),
                (string) $validated['upload_id'],
                $request->file('chunk'),
                (int) $validated['chunk_index'],
                (int) $validated['total_chunks'],
                (int) $validated['total_size'],
                (string) $validated['filename'],
                isset($validated['mime_type']) ? (string) $validated['mime_type'] : null,
                isset($validated['chunk_hash']) ? (string) $validated['chunk_hash'] : null,
            );
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function completeTranscriberPackageUpload(
        Request $request,
        ChunkedUploadService $chunks,
        TranscriberPackageService $packages,
    ): JsonResponse {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'version' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._+-]+$/'],
        ], [
            'version.regex' => 'The version may only contain letters, numbers, dots, underscores, plus signs, and hyphens.',
        ]);

        $uploadId = (string) $validated['upload_id'];
        $ownerKey = $this->packageUploadOwnerKey($request);

        try {
            $assembled = $chunks->assemble('transcriber-package', $ownerKey, $uploadId);

            if (! str_ends_with(strtolower($assembled['filename']), '.zip')) {
                throw new \RuntimeException('The Transcriber App Package must be a ZIP file.');
            }

            $published = $packages->publish(
                (string) $validated['version'],
                new UploadedFile(
                    $assembled['path'],
                    $assembled['filename'],
                    $assembled['mime_type'],
                    null,
                    true,
                ),
            );
        } catch (Throwable $exception) {
            $errorId = (string) Str::uuid();

            Log::error('Chunked Transcriber App Package upload failed.', [
                'error_id' => $errorId,
                'user_id' => $request->user()?->id,
                'upload_id' => $uploadId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return response()->json([
                'message' => $packages->uploadError($exception, $errorId),
                'error_id' => $errorId,
            ], 500);
        } finally {
            $chunks->cleanup('transcriber-package', $ownerKey, $uploadId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transcriber App Package uploaded successfully!',
            'version' => $published['version'],
            'zipfile' => $published['zipfile'],
        ]);
    }

    private function packageUploadOwnerKey(Request $request): string
    {
        return 'user-'.$request->user()?->id.'-transcriber-package';
    }
}
