<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class TaskCallbackRef
{
    private const TTL_SECONDS = 3600;

    public static function remember(string $taskId, ?string $ref = null): string
    {
        $ref ??= substr(hash('sha256', $taskId), 0, 8);
        Cache::put(self::taskKey($ref), $taskId, now()->addSeconds(self::TTL_SECONDS));

        return $ref;
    }

    public static function resolve(string $ref): ?string
    {
        $taskId = Cache::get(self::taskKey($ref));

        return is_string($taskId) && $taskId !== '' ? $taskId : null;
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function rememberStatuses(string $ref, array $statuses): void
    {
        Cache::put(self::statusKey($ref), $statuses, now()->addSeconds(self::TTL_SECONDS));
    }

    /**
     * @return list<string>
     */
    public static function resolveStatuses(string $ref): array
    {
        $statuses = Cache::get(self::statusKey($ref));

        if (! is_array($statuses)) {
            return [];
        }

        $result = [];

        foreach ($statuses as $status) {
            if (is_string($status) && $status !== '') {
                $result[] = $status;
            }
        }

        return $result;
    }

    private static function taskKey(string $ref): string
    {
        return 'telegram:tm:task:'.$ref;
    }

    private static function statusKey(string $ref): string
    {
        return 'telegram:tm:statuses:'.$ref;
    }
}
