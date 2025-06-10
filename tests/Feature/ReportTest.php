<?php
// tests/Feature/ReportTest.php

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Discussion;
use App\Models\Report;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function report_belongs_to_discussion()
    {
        $discussion = Discussion::factory()->create();
        $report = Report::factory()->create([
            'reportable_type' => 'App\Models\Discussion',
            'reportable_id' => $discussion->id,
        ]);

        $this->assertInstanceOf(Discussion::class, $report->reportable);
        $this->assertEquals($discussion->id, $report->reportable->id);
    }

    /** @test */
    public function approved_report_deletes_discussion()
    {
        $discussion = Discussion::factory()->create();
        $report = Report::factory()->create([
            'reportable_type' => 'App\Models\Discussion',
            'reportable_id' => $discussion->id,
            'status' => 'pending',
        ]);

        // Имитация модератора
        $moderator = User::factory()->create(['role' => 'moderator']);
        auth()->login($moderator);

        // Одобрение жалобы
        $report->update(['status' => 'approved']);

        // Обсуждение должно быть удалено
        $this->assertModelMissing($discussion);
    }
}
