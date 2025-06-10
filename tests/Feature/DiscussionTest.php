<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Discussion;

class DiscussionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function discussion_belongs_to_user()
    {
        $user = User::factory()->create();
        $discussion = Discussion::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $discussion->user);
        $this->assertEquals($user->id, $discussion->user->id);
    }

    /** @test */
    public function discussion_has_many_replies()
    {
        $discussion = Discussion::factory()->hasReplies(3)->create();

        $this->assertCount(3, $discussion->replies);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $discussion->replies);
    }

    /** @test */
    public function discussion_has_morph_many_media()
    {
        $discussion = Discussion::factory()->create();
        $media = \App\Models\Media::factory()->create([
            'mediable_type' => 'App\Models\Discussion',
            'mediable_id' => $discussion->id,
        ]);

        $this->assertCount(1, $discussion->media);
        $this->assertTrue($discussion->media->contains($media));
    }
}
