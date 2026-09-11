<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessReelUpload;
use App\Models\MediaUpload;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class MediaUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mime' => ['required', 'in:video/mp4,video/quicktime,video/webm'],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('media.max_reel_bytes')],
            'checksum_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $upload = MediaUpload::create([
            'user_id' => $request->user()->id,
            'disk' => config('media.upload_disk'),
            'path' => 'reel-uploads/'.$request->user()->id.'/pending',
            'expected_mime' => $data['mime'],
            'expected_size' => $data['size'],
            'checksum_sha256' => isset($data['checksum_sha256']) ? strtolower($data['checksum_sha256']) : null,
            'state' => 'pending',
            'expires_at' => now()->addMinutes(config('media.upload_url_ttl_minutes')),
        ]);
        $upload->update(['path' => 'reel-uploads/'.$request->user()->id.'/'.$upload->id]);
        $directUpload = $upload->disk === 'local'
            ? ['url' => URL::temporarySignedRoute('api.uploads.put', $upload->expires_at, ['upload' => $upload->id]), 'headers' => ['Content-Type' => $upload->expected_mime]]
            : Storage::disk($upload->disk)->temporaryUploadUrl($upload->path, $upload->expires_at, ['ContentType' => $upload->expected_mime]);

        return ApiResponse::success(['id' => $upload->id, 'state' => $upload->state, 'upload_url' => $directUpload['url'], 'upload_headers' => $directUpload['headers'], 'expires_at' => $upload->expires_at], status: 201);
    }

    public function put(MediaUpload $upload, Request $request): JsonResponse
    {
        if ($upload->state !== 'pending' || $upload->expires_at->isPast()) {
            return ApiResponse::error('UPLOAD_EXPIRED', 'The upload URL is no longer valid.', 410);
        }
        if ($request->header('Content-Type') !== $upload->expected_mime) {
            return ApiResponse::error('VALIDATION', 'The content type does not match the upload request.', 422, ['content_type' => ['Content type mismatch.']]);
        }
        $stream = $request->getContent(true);
        if (! is_resource($stream) || ! Storage::disk($upload->disk)->writeStream($upload->path, $stream)) {
            return ApiResponse::error('SERVICE_DEGRADED', 'The media could not be stored.', 503);
        }
        $size = Storage::disk($upload->disk)->size($upload->path);
        if ($size !== $upload->expected_size || $size > config('media.max_reel_bytes')) {
            Storage::disk($upload->disk)->delete($upload->path);

            return ApiResponse::error('VALIDATION', 'The uploaded file size does not match the request.', 422, ['size' => ['File size mismatch.']]);
        }
        $upload->update(['state' => 'uploaded', 'actual_size' => $size, 'uploaded_at' => now()]);

        return ApiResponse::success(['id' => $upload->id, 'state' => 'uploaded']);
    }

    public function show(MediaUpload $upload, Request $request): JsonResponse
    {
        abort_unless($upload->user_id === $request->user()->id, 404);

        return ApiResponse::success(['id' => $upload->id, 'state' => $upload->state, 'probe' => $upload->probe, 'failure_reason' => $upload->failure_reason]);
    }

    public function complete(MediaUpload $upload, Request $request): JsonResponse
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
        if ($upload->state !== 'uploaded') {
            return ApiResponse::error('CONFLICT', 'The upload is not ready to process.', 409);
        }
        $disk = Storage::disk($upload->disk);
        if (! $disk->exists($upload->path) || $disk->size($upload->path) !== $upload->expected_size) {
            return ApiResponse::error('VALIDATION', 'The uploaded file size does not match the request.', 422, ['size' => ['File size mismatch.']]);
        }
        $detectedMime = $disk->mimeType($upload->path);
        if (! in_array($detectedMime, ['video/mp4', 'video/quicktime', 'video/webm', 'application/octet-stream'], true)) {
            Storage::disk($upload->disk)->delete($upload->path);
            $upload->update(['state' => 'rejected', 'failure_reason' => 'INVALID_MEDIA_TYPE']);

            return ApiResponse::error('VALIDATION', 'The uploaded file is not a supported video.', 422, ['media' => ['Unsupported media type.']]);
        }
        $stream = $disk->readStream($upload->path);
        if (! is_resource($stream)) {
            return ApiResponse::error('SERVICE_DEGRADED', 'The media could not be read for verification.', 503);
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);
        $checksum = hash_final($hash);
        if ($upload->checksum_sha256 && ! hash_equals($upload->checksum_sha256, $checksum)) {
            Storage::disk($upload->disk)->delete($upload->path);
            $upload->update(['state' => 'rejected', 'failure_reason' => 'CHECKSUM_MISMATCH']);

            return ApiResponse::error('VALIDATION', 'The upload checksum does not match.', 422, ['checksum_sha256' => ['Checksum mismatch.']]);
        }
        $upload->update(['state' => 'queued', 'checksum_sha256' => $checksum]);
        ProcessReelUpload::dispatch($upload->id);

        return ApiResponse::success(['id' => $upload->id, 'state' => 'queued'], status: 202);
    }
}
