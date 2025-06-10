<?php
// tests/Feature/ReactionTest.php

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Discussion;
use App\Models\Reaction;

class ReactionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reaction_belongs_to_discussion()
    {
        $discussion = Discussion::factory()->create();
        $reaction = Reaction::factory()->create([
            'reactable_type' => 'App\Models\Discussion',
            'reactable_id' => $discussion->id,
        ]);

        $this->assertInstanceOf(Discussion::class, $reaction->reactable);
        $this->assertEquals($discussion->id, $reaction->reactable->id);
    }

    /** @test */
    public function user_can_like_and_dislike()
    {
        $discussion = Discussion::factory()->create();
        $user = User::factory()->create();
        auth()->login($user);

        // Добавляем лайк
        $like = Reaction::create([
            'user_id' => $user->id,
            'reactable_id' => $discussion->id,
            'reactable_type' => 'App\Models\Discussion',
            'reaction' => 'like',
        ]);

        $this->assertEquals('like', $like->reaction);

        // Меняем на дизлайк
        $like->update(['reaction' => 'dislike']);
        $this->assertEquals('dislike', $like->reaction);
    }
}
