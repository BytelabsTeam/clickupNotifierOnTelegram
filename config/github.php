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

return [
    'token' => env('GITHUB_TOKEN'),
    'org' => env('GITHUB_ORG'),
    'repos' => $decodedRepos,
    'user_logins' => is_array($logins) ? $logins : [],
];
