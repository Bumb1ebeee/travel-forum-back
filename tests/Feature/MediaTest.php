<?php
// tests/Feature/MediaTest.php

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Discussion;
use App\Models\Media;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function media_is_polymorphic()
    {
        $discussion = Discussion::factory()->create();
        $media = Media::factory()->create([
            'mediable_type' => 'App\Models\Discussion',
            'mediable_id' => $discussion->id,
        ]);

        $this->assertInstanceOf(Discussion::class, $media->mediable);
        $this->assertEquals($discussion->id, $media->mediable->id);
    }

    /** @test */
    public function media_attached_to_reply()
    {
        $reply = \App\Models\Reply::factory()->create();
        $media = Media::factory()->create([
            'mediable_type' => 'App\Models\Reply',
            'mediable_id' => $reply->id,
        ]);

        $this->assertInstanceOf(\App\Models\Reply::class, $media->mediable);
        $this->assertEquals($reply->id, $media->mediable->id);
    }
}
