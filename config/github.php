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

return [
    'token' => env('GITHUB_TOKEN'),
    'org' => env('GITHUB_ORG'),
    'repos' => $decodedRepos,
    'user_logins' => is_array($logins) ? $logins : [],
    'default_weight' => (float) env('GITHUB_DEFAULT_WEIGHT', 0.8),
    'repo_weights' => $normalizedWeights,
];
