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
use Illuminate\Support\Facades\DB;
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

    public function test_probe_is_authoritative_and_rejects_video_over_configured_duration(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::disk('local')->put('reel-uploads/too-long', 'video');
        $upload = MediaUpload::create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'reel-uploads/too-long', 'expected_mime' => 'video/mp4', 'expected_size' => 5, 'actual_size' => 5, 'state' => 'queued', 'expires_at' => now()->addHour()]);
        $probe = new class implements MediaProbe
        {
            public function inspect(string $absolutePath): array
            {
                return ['duration_ms' => 60001, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920];
            }
        };

        (new ProcessReelUpload($upload->id))->handle($probe);
        $this->assertDatabaseHas('media_uploads', ['id' => $upload->id, 'state' => 'rejected', 'failure_reason' => 'UPLOAD_TOO_LONG']);
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

    public function test_transcode_completion_moves_reel_to_pending_review(): void
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
            public function transcode(string $source, string $outputDirectory): array
            {
                return ['video' => $outputDirectory.'/video.mp4', 'thumbnail' => $outputDirectory.'/thumbnail.jpg', 'safety' => ['blank_frame_scan' => 'passed']];
            }
        };
        (new TranscodeReelMedia($reel))->handle($transcoder);
        $this->assertDatabaseHas('reels', ['id' => $reel, 'state' => 'pending_review']);
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
            public function transcode(string $source, string $outputDirectory): array
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
}
