<?php

$repos = env('GITHUB_REPOS', '');
$decodedRepos = json_decode((string) $repos, true);

if (! is_array($decodedRepos)) {
    $decodedRepos = array_values(array_filter(array_map(
        static fn (string $repo): string => trim($repo),
        explode(',', (string) $repos)
    )));
}

$logins = json_decode(env('GITHUB_USER_LOGINS', '{}'), true);

$weights = json_decode(env('GITHUB_REPO_WEIGHTS', '{}'), true);
$normalizedWeights = [];

if (is_array($weights)) {
    foreach ($weights as $repo => $weight) {
        if (! is_string($repo) || trim($repo) === '' || ! is_numeric($weight)) {
            continue;
        }

        $normalizedWeights[mb_strtolower(trim($repo))] = (float) $weight;
    }
}

$ignoreDeletionsRaw = env('GITHUB_IGNORE_DELETIONS_REPOS', '');
$decodedIgnoreDeletions = json_decode((string) $ignoreDeletionsRaw, true);

if (! is_array($decodedIgnoreDeletions)) {
    $decodedIgnoreDeletions = array_values(array_filter(array_map(
        static fn (string $repo): string => trim($repo),
        explode(',', (string) $ignoreDeletionsRaw)
    )));
}

$ignoreDeletionsRepos = [];

foreach ($decodedIgnoreDeletions as $repo) {
    if (! is_string($repo) || trim($repo) === '') {
        continue;
    }

    $ignoreDeletionsRepos[mb_strtolower(trim($repo))] = true;
}

return [
    'token' => env('GITHUB_TOKEN'),
    'org' => env('GITHUB_ORG'),
    'repos' => $decodedRepos,
    'user_logins' => is_array($logins) ? $logins : [],
    'default_weight' => (float) env('GITHUB_DEFAULT_WEIGHT', 0.8),
    'repo_weights' => $normalizedWeights,
    'ignore_deletions_repos' => $ignoreDeletionsRepos,
    'commit_poll_lookback_minutes' => (int) env('GITHUB_COMMIT_POLL_LOOKBACK_MINUTES', 15),
    'commit_poll_interval_seconds' => (int) env('GITHUB_COMMIT_POLL_INTERVAL', 60),
];
