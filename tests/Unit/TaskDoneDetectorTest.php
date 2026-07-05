<?php

namespace Tests\Unit;

use App\Support\TaskDoneDetector;
use Tests\TestCase;

class TaskDoneDetectorTest extends TestCase
{
    public function test_it_detects_closed_status_as_done(): void
    {
        $detector = new TaskDoneDetector;

        $payload = [
            'event' => 'taskStatusUpdated',
            'history_items' => [
                [
                    'field' => 'status',
                    'after' => [
                        'status' => 'complete',
                        'type' => 'closed',
                    ],
                ],
            ],
        ];

        $this->assertTrue($detector->isTaskDone($payload));
    }

    public function test_it_detects_configured_done_status_names(): void
    {
        config(['clickup.done_statuses' => ['done', 'تکمیل']]);

        $detector = new TaskDoneDetector;

        $payload = [
            'event' => 'taskStatusUpdated',
            'history_items' => [
                [
                    'field' => 'status',
                    'after' => [
                        'status' => 'تکمیل',
                        'type' => 'custom',
                    ],
                ],
            ],
        ];

        $this->assertTrue($detector->isTaskDone($payload));
    }

    public function test_it_ignores_non_done_status_updates(): void
    {
        $detector = new TaskDoneDetector;

        $payload = [
            'event' => 'taskStatusUpdated',
            'history_items' => [
                [
                    'field' => 'status',
                    'after' => [
                        'status' => 'in progress',
                        'type' => 'custom',
                    ],
                ],
            ],
        ];

        $this->assertFalse($detector->isTaskDone($payload));
    }

    public function test_it_ignores_other_events(): void
    {
        $detector = new TaskDoneDetector;

        $payload = [
            'event' => 'taskCreated',
            'history_items' => [],
        ];

        $this->assertFalse($detector->isTaskDone($payload));
    }
}
