<?php

declare(strict_types=1);

/**
 * What must not end up in the TER upload artifact.
 *
 * `tailor create-artefact` zips the *working tree*. A CI checkout is the whole
 * repository, so without this file the archive published to the TYPO3 Extension
 * Repository carries the developer documentation, the development site package
 * and the agent instructions — about 680 KiB of a 1.8 MiB archive, none of which
 * an installation has any use for.
 *
 * The repository already says which paths those are. `.gitattributes` marks them
 * `export-ignore`, which is what keeps them out of the archive GitHub generates
 * and therefore out of every composer installation. That declaration is read
 * here rather than repeated, so the two distribution channels cannot drift: with
 * this configuration in place the TER artifact holds exactly the entries
 * `git archive HEAD` produces.
 *
 * Tailor's own defaults are merged in rather than copied. It **replaces** its
 * configuration with this file instead of merging — the two lists returned here
 * are the complete rule set — and the workflow installs tailor unpinned, so a
 * copied list silently stops matching the tool that reads it. The lists really
 * do move: 2.0.0 and the current development version already differ by a dozen
 * entries.
 *
 * Point tailor at this file with the `TYPO3_EXCLUDE_FROM_PACKAGING` environment
 * variable — see `.github/workflows/publish.yml`.
 *
 * → `docs/workflow/releasing.md`
 */
$tailorDefaults = static function (): array {
    if (!class_exists(\TYPO3\Tailor\Service\VersionService::class)) {
        throw new \RuntimeException(
            'This file is tailor configuration and is meant to be read by tailor itself.',
            1786706301
        );
    }

    $versionService = (new \ReflectionClass(\TYPO3\Tailor\Service\VersionService::class))->getFileName();
    // "<tailor>/src/Service/VersionService.php" up to "<tailor>".
    $defaults = dirname((string)$versionService, 3) . '/conf/ExcludeFromPackaging.php';

    if (!is_file($defaults)) {
        throw new \RuntimeException(
            'Could not locate the tailor packaging defaults, expected at: ' . $defaults,
            1786706302
        );
    }

    return require $defaults;
};

$exportIgnored = static function (): array {
    $gitattributes = dirname(__DIR__, 2) . '/.gitattributes';

    if (!is_file($gitattributes)) {
        throw new \RuntimeException('Could not read: ' . $gitattributes, 1786706303);
    }

    $directories = [];
    $files = [];

    foreach (file($gitattributes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $path = array_shift($parts);

        // The attributes are matched as a list rather than as a whole line, so
        // that a second attribute on the same line cannot make a declaration
        // stop being seen. That failure would be silent and would ship the path.
        if ($path === null || !str_starts_with($path, '/') || !in_array('export-ignore', $parts, true)) {
            continue;
        }

        $path = substr($path, 1);

        // Tailor matches a directory against the path relative to the package
        // root, anchored at its start, and a file against its *name* alone,
        // anchored at its end. Both entries are regular expression fragments, so
        // what is written in ".gitattributes" is quoted before it is handed over.
        if (is_dir(dirname(__DIR__, 2) . '/' . $path)) {
            $directories[] = preg_quote($path, '/') . '(?:\/|$)';
            continue;
        }

        // A path can only be anchored at the root for directories. A file named
        // like a root level one is therefore excluded wherever it sits — which
        // is a limitation of tailor's matching, not a decision taken here.
        $files[] = '^' . preg_quote($path, '/');
    }

    // Nothing here is allowed to fail quietly: this file exists to keep paths
    // out of a published archive, and an archive that is missing an exclusion
    // looks exactly like one that needs none. A release can be withdrawn from
    // GitHub; a version published to the TER cannot be published again.
    if ($directories === [] && $files === []) {
        throw new \RuntimeException(
            'No "export-ignore" declaration was found in: ' . $gitattributes,
            1786706304
        );
    }

    return ['directories' => $directories, 'files' => $files];
};

$defaults = $tailorDefaults();
$additional = $exportIgnored();

return [
    'directories' => array_values(array_unique([...$defaults['directories'], ...$additional['directories']])),
    'files' => array_values(array_unique([...$defaults['files'], ...$additional['files']])),
];
