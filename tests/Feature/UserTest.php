<?php
// tests/Feature/UserTest.php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_create_discussion()
    {
        $user = User::factory()->create();
        $discussion = $user->discussions()->create([
            'title' => 'Мой маршрут',
            'description' => '<p>Описание маршрута</p>',
            'category_id' => 1,
            'is_draft' => true,
        ]);

        $this->assertDatabaseHas('discussions', [
            'title' => 'Мой маршрут',
            'user_id' => $user->id,
        ]);
    }

    /** @test */
    public function user_has_roles()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $moderator = User::factory()->create(['role' => 'moderator']);
        $user = User::factory()->create(['role' => 'user']);

        $this->assertEquals('admin', $admin->role);
        $this->assertEquals('moderator', $moderator->role);
        $this->assertEquals('user', $user->role);
    }
}
