<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaRouteTest extends TestCase
{
    public function test_media_route_supports_nested_paths(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/cache/test.mp3', 'fake-audio-bytes');

        $url = route('media.file', ['path' => 'audio/cache/test.mp3']);
        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_media_route_supports_byte_range_requests_for_seeking(): void
    {
        Storage::fake('public');
        $content = 'fake-audio-bytes';
        Storage::disk('public')->put('audio/cache/test.mp3', $content);

        $url = route('media.file', ['path' => 'audio/cache/test.mp3']);
        $response = $this->withHeaders([
            'Range' => 'bytes=0-3',
        ])->get($url);

        $response->assertStatus(206);
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Range', 'bytes 0-3/'.strlen($content));
        $response->assertHeader('Content-Length', '4');
        $this->assertSame(substr($content, 0, 4), $response->streamedContent());
    }

    public function test_media_route_serves_tracked_public_audio_assets(): void
    {
        Storage::fake('public');

        $relativePath = 'audio/public-media-test.mp3';
        $absolutePath = public_path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'public-audio-bytes');

        try {
            $response = $this->get(route('media.file', ['path' => $relativePath]));

            $response->assertOk();
            $response->assertHeader('Access-Control-Allow-Origin', '*');
            $response->assertHeader('Content-Type', 'audio/mpeg');
        } finally {
            File::delete($absolutePath);
        }
    }
}
