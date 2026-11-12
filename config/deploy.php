<?php

$adminIds = array_values(array_filter(array_map(
    static function (string $id): int {
        return (int) trim($id);
    },
    explode(',', (string) env('DEPLOY_ADMIN_IDS', '103063134,1080648232'))
), static fn (int $id): bool => $id > 0));

return [
    'admin_ids' => $adminIds,
    'github_repo' => env('DEPLOY_GITHUB_REPO', 'BytelabsTeam/clickupNotifierOnTelegram'),
    'github_branch' => env('DEPLOY_GITHUB_BRANCH', 'main'),
];
