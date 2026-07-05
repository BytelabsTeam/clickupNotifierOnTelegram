<?php

namespace Tests\Unit;

use App\Support\TaskDoneDetector;
use Tests\TestCase;

class TaskDoneDetectorApiTaskTest extends TestCase
{
    public function test_it_detects_closed_api_task_as_done(): void
    {
        $detector = new TaskDoneDetector;

        $task = [
            'status' => [
                'status' => 'complete',
                'type' => 'closed',
            ],
        ];

        $this->assertTrue($detector->isApiTaskDone($task));
    }

    public function test_it_detects_configured_done_status_on_api_task(): void
    {
        config(['clickup.done_statuses' => ['done', 'تکمیل']]);

        $detector = new TaskDoneDetector;

        $task = [
            'status' => [
                'status' => 'تکمیل',
                'type' => 'custom',
            ],
        ];

        $this->assertTrue($detector->isApiTaskDone($task));
    }

    public function test_it_ignores_in_progress_api_task(): void
    {
        $detector = new TaskDoneDetector;

        $task = [
            'status' => [
                'status' => 'in progress',
                'type' => 'custom',
            ],
        ];

        $this->assertFalse($detector->isApiTaskDone($task));
    }
}
