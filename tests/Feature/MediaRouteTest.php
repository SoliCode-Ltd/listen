<?php

namespace Tests\Feature;

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
}
