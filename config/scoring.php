<?php

$aliases = json_decode((string) env('SCORE_EMAIL_ALIASES', '{}'), true);
$normalized = [];

if (is_array($aliases)) {
    foreach ($aliases as $alias => $canonical) {
        if (! is_string($alias) || ! is_string($canonical)) {
            continue;
        }

        $alias = mb_strtolower(trim($alias));
        $canonical = mb_strtolower(trim($canonical));

        if ($alias === '' || $canonical === '') {
            continue;
        }

        $normalized[$alias] = $canonical;
    }
}

return [
    // Map alternate emails → canonical email so ClickUp + GitHub scores merge.
    // Example: {"github-only@gmail.com":"clickup@gmail.com"}
    'email_aliases' => $normalized,
];
