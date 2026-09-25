<?php

namespace Tests\Unit;

use App\Support\ReelPlayback;
use Tests\TestCase;

final class ReelPlaybackTest extends TestCase
{
    public function test_mux_thumbnail_is_derived_from_playback_url(): void
    {
        $this->assertSame(
            'https://image.mux.com/abc123/thumbnail.jpg',
            ReelPlayback::muxThumbnailFromPlayback('https://stream.mux.com/abc123.m3u8'),
        );
        $this->assertNull(ReelPlayback::muxThumbnailFromPlayback('https://cdn.example.com/video.mp4'));
        $this->assertTrue(ReelPlayback::keepUploadedCover('reels/1/cover.jpg'));
        $this->assertFalse(ReelPlayback::keepUploadedCover('https://image.mux.com/abc123/thumbnail.jpg'));
        $this->assertFalse(ReelPlayback::keepUploadedCover(null));
    }
}
