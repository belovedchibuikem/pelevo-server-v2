<?php

namespace Tests\Feature\Api;

use App\Contracts\MediaProbe;
use App\Contracts\MediaTranscoder;
use App\Jobs\ProcessReelUpload;
use App\Jobs\TranscodeReelMedia;
use App\Models\CreatorProfile;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaUploadPipelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_signed_upload_is_verified_and_queued_for_server_probe(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessReelUpload::class]);
        $user = User::factory()->create();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads', ['mime' => 'video/mp4', 'size' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes)])->assertCreated()->json('data');

        $this->call('PUT', $created['upload_url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], $bytes)->assertOk()->assertJsonPath('data.state', 'uploaded');
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/uploads/{$created['id']}/complete")->assertStatus(202)->assertJsonPath('data.state', 'queued');
        Queue::assertPushed(ProcessReelUpload::class, fn (ProcessReelUpload $job): bool => $job->uploadId === $created['id']);
        $this->assertDatabaseHas('media_uploads', ['id' => $created['id'], 'state' => 'queued', 'actual_size' => strlen($bytes)]);
    }

    public function test_probe_caps_overlong_video_and_marks_it_truncated(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::disk('local')->put('reel-uploads/too-long', 'video');
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'reel-uploads/too-long', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'queued', 'expires_at' => now()->addHour()]);
        $probe = new class implements MediaProbe
        {
            public function inspect(string $absolutePath): array
            {
                return ['duration_ms' => 180001, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920];
            }
        };

        (new ProcessReelUpload($upload->id))->handle($probe);
        $upload->refresh();
        $this->assertSame('processed', $upload->state);
        $this->assertNull($upload->failure_reason);
        $this->assertTrue((bool) ($upload->probe['truncated'] ?? false));
        $this->assertSame(180000, $upload->probe['duration_ms']);
        $this->assertSame(180001, $upload->probe['original_duration_ms']);
    }

    public function test_overlong_processed_upload_creates_a_truncated_reel(): void
    {
        Queue::fake([TranscodeReelMedia::class]);
        $user = User::factory()->create();
        CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'reel-uploads/ready', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'processed', 'probe' => ['duration_ms' => 180000, 'original_duration_ms' => 240000, 'truncated' => true, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920], 'expires_at' => now()->addHour(), 'processed_at' => now()]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/reels', ['upload_id' => $upload->id, 'caption' => 'Ready'])->assertCreated()->assertJsonPath('data.state', 'processing')->assertJsonPath('data.duration_ms', 180000)->assertJsonPath('data.truncated', true)->assertJsonPath('data.original_duration_ms', 240000);
        Queue::assertPushed(TranscodeReelMedia::class);
    }

    public function test_processed_upload_can_create_only_one_pending_review_reel(): void
    {
        Queue::fake([TranscodeReelMedia::class]);
        $user = User::factory()->create();
        CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'reel-uploads/ready', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'processed', 'probe' => ['duration_ms' => 60000, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920], 'expires_at' => now()->addHour(), 'processed_at' => now()]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/reels', ['upload_id' => $upload->id, 'caption' => 'Ready'])->assertCreated()->assertJsonPath('data.state', 'processing')->assertJsonPath('data.duration_ms', 60000);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/reels', ['upload_id' => $upload->id])->assertConflict();
        $this->assertDatabaseCount('reel_media', 1);
        Queue::assertPushed(TranscodeReelMedia::class);
    }

    public function test_transcode_completion_publishes_the_reel(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        Storage::disk('local')->put('source.mp4', 'video');
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'source.mp4', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'processed', 'probe' => ['duration_ms' => 3000, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920], 'expires_at' => now()->addHour()]);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'processing', 'duration_ms' => 3000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_media')->insert(['id' => (string) Str::ulid(), 'reel_id' => $reel, 'media_upload_id' => $upload->id, 'mime' => 'video/mp4', 'duration_ms' => 3000, 'processing_state' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $transcoder = new class implements MediaTranscoder
        {
            public function transcode(string $source, string $outputDirectory, ?int $maxDurationMs = null): array
            {
                return ['video' => $outputDirectory.'/video.mp4', 'thumbnail' => $outputDirectory.'/thumbnail.jpg', 'safety' => ['blank_frame_scan' => 'passed']];
            }
        };
        (new TranscodeReelMedia($reel))->handle($transcoder);
        $this->assertDatabaseHas('reels', ['id' => $reel, 'state' => 'published']);
        $this->assertDatabaseHas('reel_media', ['reel_id' => $reel, 'processing_state' => 'ready']);
    }

    public function test_cloud_media_is_staged_transcoded_and_written_back_to_supabase_disk(): void
    {
        Storage::fake('supabase');
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Cloud Creator']);
        Storage::disk('supabase')->put('source.mp4', 'video');
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'supabase', 'path' => 'source.mp4', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'processed', 'probe' => ['duration_ms' => 3000, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920], 'expires_at' => now()->addHour()]);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'processing', 'duration_ms' => 3000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_media')->insert(['id' => (string) Str::ulid(), 'reel_id' => $reel, 'media_upload_id' => $upload->id, 'mime' => 'video/mp4', 'duration_ms' => 3000, 'processing_state' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $transcoder = new class implements MediaTranscoder
        {
            public function transcode(string $source, string $outputDirectory, ?int $maxDurationMs = null): array
            {
                file_put_contents($outputDirectory.'/video.mp4', 'transcoded');
                file_put_contents($outputDirectory.'/thumbnail.jpg', 'thumbnail');

                return ['video' => $outputDirectory.'/video.mp4', 'thumbnail' => $outputDirectory.'/thumbnail.jpg', 'safety' => ['blank_frame_scan' => 'passed']];
            }
        };

        (new TranscodeReelMedia($reel))->handle($transcoder);

        Storage::disk('supabase')->assertExists("reels/{$reel}/video.mp4");
        Storage::disk('supabase')->assertExists("reels/{$reel}/thumbnail.jpg");
        $this->assertDatabaseHas('reel_media', ['reel_id' => $reel, 'processing_state' => 'ready', 'transcoded_path' => "reels/{$reel}/video.mp4"]);
    }

    public function test_local_upload_url_is_host_relative_so_the_app_can_put_to_its_api_origin(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Storage::fake('local');
        $user = User::factory()->create();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads', ['mime' => 'video/mp4', 'size' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes)])->assertCreated()->json('data');

        $this->assertNull(parse_url($created['upload_url'], PHP_URL_HOST));
        $this->assertStringStartsWith('/api/v1/uploads/'.$created['id'].'/content?', $created['upload_url']);
    }

    public function test_signed_put_still_works_when_the_app_puts_to_a_different_api_host(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Storage::fake('local');
        $user = User::factory()->create();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads', ['mime' => 'video/mp4', 'size' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes)])->assertCreated()->json('data');
        $rewritten = 'http://10.0.2.2:8000'.$created['upload_url'];

        $this->call('PUT', $rewritten, [], [], [], ['CONTENT_TYPE' => 'video/mp4', 'HTTP_HOST' => '10.0.2.2:8000'], $bytes)->assertOk()->assertJsonPath('data.state', 'uploaded');
    }

    public function test_authenticated_multipart_upload_stores_reserved_bytes(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessReelUpload::class]);
        $user = User::factory()->create();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads', ['mime' => 'video/mp4', 'size' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes)])->assertCreated()->json('data');
        $tmp = tempnam(sys_get_temp_dir(), 'pelevo-reel-');
        file_put_contents($tmp, $bytes);

        $this->actingAs($user, 'sanctum')->post(
            "/api/v1/uploads/{$created['id']}/content",
            ['file' => new UploadedFile($tmp, 'clip.mp4', 'video/mp4', null, true)],
        )->assertOk()->assertJsonPath('data.state', 'uploaded');
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/uploads/{$created['id']}/complete")->assertStatus(202);
        Queue::assertPushed(ProcessReelUpload::class);

        @unlink($tmp);
    }

    public function test_probe_rejects_checksum_mismatch(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::disk('local')->put('reel-uploads/mismatch', 'video');
        $upload = MediaUpload::create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => 'reel-uploads/mismatch',
            'expected_mime' => 'video/mp4',
            'expected_size' => 5,
            'actual_size' => 5,
            'checksum_sha256' => hash('sha256', 'other'),
            'state' => 'queued',
            'expires_at' => now()->addHour(),
        ]);

        (new ProcessReelUpload($upload->id))->handle(new class implements MediaProbe
        {
            public function inspect(string $absolutePath): array
            {
                return ['duration_ms' => 3000, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920];
            }
        });
        $upload->refresh();
        $this->assertSame('rejected', $upload->state);
        $this->assertSame('CHECKSUM_MISMATCH', $upload->failure_reason);
    }

    public function test_production_mux_reservation_returns_a_direct_upload_url(): void
    {
        config([
            'media.direct_upload' => 'mux',
            'services.mux.token_id' => 'mux-id',
            'services.mux.token_secret' => 'mux-secret',
        ]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/video/v1/uploads')) {
                return Http::response(['data' => ['id' => 'mux_upload_1', 'url' => 'https://storage.googleapis.com/mux-uploads/abc']], 201);
            }

            return Http::response(['error' => $request->url()], 500);
        });
        $user = User::factory()->create();
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads', ['mime' => 'video/mp4', 'size' => 2048])->assertCreated()->json('data');

        $this->assertSame('mux', $created['provider']);
        $this->assertSame('https://storage.googleapis.com/mux-uploads/abc', $created['upload_url']);
        $this->assertSame('application/octet-stream', $created['upload_headers']['Content-Type']);
        $this->assertDatabaseHas('media_uploads', ['id' => $created['id'], 'disk' => 'mux', 'path' => 'mux_upload_1']);
    }

    public function test_mux_complete_waits_for_the_asset_and_marks_the_upload_processed(): void
    {
        config([
            'media.direct_upload' => 'mux',
            'media.process_inline' => false,
            'services.mux.token_id' => 'mux-id',
            'services.mux.token_secret' => 'mux-secret',
        ]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/video/v1/uploads/mux_upload_1')) {
                return Http::response(['data' => ['status' => 'asset_created', 'asset_id' => 'asset1']], 200);
            }
            if (str_contains($request->url(), '/video/v1/assets/asset1')) {
                return Http::response(['data' => [
                    'status' => 'ready',
                    'duration' => 8.25,
                    'playback_ids' => [['id' => 'play1']],
                    'tracks' => [['type' => 'video', 'max_width' => 1080, 'max_height' => 1920]],
                ]], 200);
            }

            return Http::response(['error' => $request->url()], 500);
        });
        Queue::fake([ProcessReelUpload::class]);
        $user = User::factory()->create();
        $upload = MediaUpload::create([
            'user_id' => $user->id,
            'disk' => 'mux',
            'path' => 'mux_upload_1',
            'expected_mime' => 'video/mp4',
            'expected_size' => 2048,
            'state' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/uploads/{$upload->id}/complete")->assertStatus(202)->assertJsonPath('data.state', 'queued');
        Queue::assertPushed(ProcessReelUpload::class, fn (ProcessReelUpload $job): bool => $job->uploadId === $upload->id);

        (new ProcessReelUpload($upload->id))->handle(new class implements MediaProbe
        {
            public function inspect(string $absolutePath): array
            {
                throw new \RuntimeException('Mux uploads must not be probed from disk.');
            }
        });
        $upload->refresh();
        $this->assertSame('processed', $upload->state);
        $this->assertSame(8250, $upload->probe['duration_ms']);
        $this->assertSame('https://stream.mux.com/play1.m3u8', $upload->probe['playback_url']);
    }

    public function test_mux_complete_never_blocks_the_http_request_even_when_inline_processing_is_on(): void
    {
        config([
            'media.direct_upload' => 'mux',
            'media.process_inline' => true,
            'services.mux.token_id' => 'mux-id',
            'services.mux.token_secret' => 'mux-secret',
        ]);
        Queue::fake([ProcessReelUpload::class]);
        $user = User::factory()->create();
        $upload = MediaUpload::create([
            'user_id' => $user->id,
            'disk' => 'mux',
            'path' => 'mux_upload_prep',
            'expected_mime' => 'video/mp4',
            'expected_size' => 2048,
            'state' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/uploads/{$upload->id}/complete")
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');
        Queue::assertPushed(ProcessReelUpload::class, fn (ProcessReelUpload $job): bool => $job->uploadId === $upload->id && $job->queue === 'default');
        $this->assertSame('queued', $upload->fresh()->state);
    }

    public function test_mux_marks_processed_as_soon_as_playback_id_exists(): void
    {
        config([
            'media.direct_upload' => 'mux',
            'services.mux.token_id' => 'mux-id',
            'services.mux.token_secret' => 'mux-secret',
        ]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/video/v1/uploads/mux_upload_prep')) {
                return Http::response(['data' => ['status' => 'asset_created', 'asset_id' => 'asset_prep']], 200);
            }
            if (str_contains($request->url(), '/video/v1/assets/asset_prep')) {
                return Http::response(['data' => [
                    'status' => 'preparing',
                    'duration' => 42.4,
                    'playback_ids' => [['id' => 'play_prep']],
                    'tracks' => [['type' => 'video', 'max_width' => 720, 'max_height' => 1280]],
                ]], 200);
            }

            return Http::response(['error' => $request->url()], 500);
        });
        $upload = MediaUpload::create([
            'user_id' => User::factory()->create()->id,
            'disk' => 'mux',
            'path' => 'mux_upload_prep',
            'expected_mime' => 'video/mp4',
            'expected_size' => 2048,
            'state' => 'queued',
            'expires_at' => now()->addHour(),
        ]);

        (new ProcessReelUpload($upload->id))->handle(new class implements MediaProbe
        {
            public function inspect(string $absolutePath): array
            {
                throw new \RuntimeException('Mux uploads must not be probed from disk.');
            }
        });
        $upload->refresh();
        $this->assertSame('processed', $upload->state);
        $this->assertSame(42400, $upload->probe['duration_ms']);
        $this->assertSame('https://stream.mux.com/play_prep.m3u8', $upload->probe['playback_url']);
        $this->assertSame(720, $upload->probe['width']);
    }

    public function test_mux_transcode_publishes_without_ffmpeg(): void
    {
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Mux Creator']);
        $upload = MediaUpload::create([
            'user_id' => $user->id,
            'disk' => 'mux',
            'path' => 'mux_upload_1',
            'expected_mime' => 'video/mp4',
            'expected_size' => 2048,
            'actual_size' => 2048,
            'state' => 'processed',
            'probe' => [
                'duration_ms' => 8000,
                'mime' => 'video/mp4',
                'width' => 1080,
                'height' => 1920,
                'playback_url' => 'https://stream.mux.com/play1.m3u8',
                'thumbnail_url' => 'https://image.mux.com/play1/thumbnail.jpg?time=1',
                'mux_asset_id' => 'asset1',
            ],
            'expires_at' => now()->addHour(),
            'processed_at' => now(),
        ]);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'processing', 'duration_ms' => 8000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_media')->insert(['id' => (string) Str::ulid(), 'reel_id' => $reel, 'media_upload_id' => $upload->id, 'mime' => 'video/mp4', 'duration_ms' => 8000, 'processing_state' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $transcoder = new class implements MediaTranscoder
        {
            public function transcode(string $source, string $outputDirectory, ?int $maxDurationMs = null): array
            {
                throw new \RuntimeException('Mux reels must not be transcoded with FFmpeg.');
            }
        };

        (new TranscodeReelMedia($reel))->handle($transcoder);
        $this->assertDatabaseHas('reels', ['id' => $reel, 'state' => 'published', 'media_url' => 'https://stream.mux.com/play1.m3u8']);
        $this->assertDatabaseHas('reel_media', ['reel_id' => $reel, 'processing_state' => 'ready', 'transcoded_path' => 'mux/asset1']);
    }

    public function test_process_reel_uploads_command_recovers_a_queued_mux_upload(): void
    {
        config([
            'media.direct_upload' => 'mux',
            'services.mux.token_id' => 'mux-id',
            'services.mux.token_secret' => 'mux-secret',
        ]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/video/v1/uploads/mux_stuck')) {
                return Http::response(['data' => ['status' => 'asset_created', 'asset_id' => 'asset_stuck']], 200);
            }
            if (str_contains($request->url(), '/video/v1/assets/asset_stuck')) {
                return Http::response(['data' => [
                    'status' => 'preparing',
                    'duration' => 12.0,
                    'playback_ids' => [['id' => 'play_stuck']],
                ]], 200);
            }

            return Http::response(['error' => $request->url()], 500);
        });
        $upload = MediaUpload::create([
            'user_id' => User::factory()->create()->id,
            'disk' => 'mux',
            'path' => 'mux_stuck',
            'expected_mime' => 'video/mp4',
            'expected_size' => 2048,
            'state' => 'queued',
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('pelevo:process-reel-uploads', ['--sync' => true])->assertSuccessful();
        $this->assertSame('processed', $upload->fresh()->state);
        $this->assertSame('https://stream.mux.com/play_stuck.m3u8', $upload->fresh()->probe['playback_url']);
    }
}
