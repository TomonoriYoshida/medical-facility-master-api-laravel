<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    public function test_rhb_download_is_scheduled_daily_in_jst(): void
    {
        $event = $this->findEvent('rhb:download');

        $this->assertNotNull($event);
        $this->assertSame('0 5 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    public function test_rhb_import_is_scheduled_thirty_minutes_after_rhb_download(): void
    {
        $event = $this->findEvent('rhb:import');

        $this->assertNotNull($event);
        $this->assertSame('30 5 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    private function findEvent(string $commandName): ?object
    {
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', $commandName)) {
                return $event;
            }
        }

        return null;
    }
}
