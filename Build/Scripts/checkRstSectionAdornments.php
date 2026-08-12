<?php

declare(strict_types=1);

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../../.Build/vendor/autoload.php';
require_once __DIR__ . '/RstSectionAdornmentChecker.php';

/**
 * Quality gate over the section adornments of "Documentation/". The algorithm
 * lives in "RstSectionAdornmentChecker.php"; this file adds the file traversal
 * and the reporting.
 *
 * Every overline and every underline has to be exactly as long as the title it
 * belongs to. reStructuredText itself is more permissive — an adornment may be
 * longer than its title, and a shorter one is only a warning — and the renderer
 * this repository uses is more permissive still: "TitleRule" asks for two
 * characters in an underline and four in an overline, and never compares either
 * against the title. "renderDocumentation --fail-on-error" therefore cannot see
 * this, the rendered page looks exactly the same either way, and a review of the
 * source reads the words rather than counting the "=" signs. The defect survives
 * all three, and shows up later as noise in the diff of the next change to that
 * heading. A gate is the only thing that catches it.
 *
 * Run "--fix" to rewrite the adornment lengths instead of only reporting them:
 *
 *   Build/Scripts/runTests.sh -s checkRstSectionAdornments
 *   Build/Scripts/runTests.sh -s checkRstSectionAdornments -- --fix
 *
 * The second finding this gate reports, an adornment character that does not
 * match the level of its section, is **never** fixed: the character says which
 * level a heading occupies, and deciding that is editorial work rather than
 * arithmetic. Rewriting it would silently restructure the document.
 *
 * See the documentation conventions in "docs/Index.md".
 */

/**
 * @param array<string, list<array{line: int, title: string, adornment: string, expected: int, actual: int}>> $findings
 * @return list<string>
 */
function formatLengthFindings(array $findings): array
{
    $report = [];
    foreach ($findings as $file => $perFile) {
        $report[] = '  ' . $file;
        foreach ($perFile as $finding) {
            $report[] = sprintf(
                '    line %d, %s of "%s": %d characters, expected %d, %d too %s',
                $finding['line'],
                $finding['adornment'],
                OutputFormatter::escape($finding['title']),
                $finding['actual'],
                $finding['expected'],
                abs($finding['actual'] - $finding['expected']),
                $finding['actual'] < $finding['expected'] ? 'short' : 'long'
            );
        }
    }

    return $report;
}

/**
 * @param array<string, list<array{line: int, title: string, level: int, expected: string, actual: string}>> $findings
 * @return list<string>
 */
function formatCharacterFindings(array $findings): array
{
    $report = [];
    foreach ($findings as $file => $perFile) {
        $report[] = '  ' . $file;
        foreach ($perFile as $finding) {
            $report[] = sprintf(
                '    line %d, "%s": level %d is adorned with %s, the convention is %s',
                $finding['line'],
                OutputFormatter::escape($finding['title']),
                $finding['level'],
                OutputFormatter::escape($finding['actual']),
                OutputFormatter::escape($finding['expected'])
            );
        }
    }

    return $report;
}

// "$argv" exists whenever "register_argc_argv" is on, which it is for the CLI
// SAPI this script runs under. Reading it through "$_SERVER" states that instead
// of assuming it, which is also what keeps static analysis out of the question.
$fix = in_array('--fix', array_slice((array)($_SERVER['argv'] ?? []), 1), true);
$output = new ConsoleOutput();
$root = dirname(__DIR__, 2);

// Only "Documentation/" is searched, which is what keeps the rendered
// documentation out of the run: "renderDocumentation" writes it to the sibling
// directory "Documentation-GENERATED-temp/", outside this search root and
// git-ignored on top of that, so "ignoreVCSIgnored" excludes it a second time.
$finder = new Finder();
$finder->files()
    ->ignoreVCSIgnored(true)
    ->name(['*.rst', '*.rst.txt'])
    ->in($root . '/Documentation')
    ->sortByName();

$checker = new RstSectionAdornmentChecker();
$lengths = [];
$characters = [];
$checked = 0;

foreach ($finder as $file) {
    $checked++;
    $content = (string)file_get_contents($file->getPathname());
    $result = $checker->check($content);
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if ($result['lengths'] !== []) {
        $lengths[$relative] = $result['lengths'];
        if ($fix) {
            file_put_contents($file->getPathname(), $result['content']);
        }
    }
    if ($result['characters'] !== []) {
        $characters[$relative] = $result['characters'];
    }
}

if ($lengths === [] && $characters === []) {
    $output->writeln(sprintf('<info>Checked %d reStructuredText files, every section adornment matches its title.</info>', $checked));
    exit(0);
}

if ($lengths !== []) {
    $output->writeln($fix
        ? sprintf('<info>Adjusted the adornment length in %d of %d reStructuredText files:</info>', count($lengths), $checked)
        : sprintf('<error>Found adornments of the wrong length in %d of %d reStructuredText files:</error>', count($lengths), $checked));
    $output->writeln(formatLengthFindings($lengths));
    $output->writeln('');
}

if ($characters !== []) {
    $output->writeln(sprintf('<error>Found adornment characters that do not match their level in %d of %d reStructuredText files:</error>', count($characters), $checked));
    $output->writeln(formatCharacterFindings($characters));
    $output->writeln('');
    $output->writeln('These are never fixed automatically: the adornment character decides which');
    $output->writeln('level a heading occupies, and changing it changes the structure of the');
    $output->writeln('document. Pick the level the heading belongs at and adorn it by hand.');
    $output->writeln('The convention is "=" overlined and underlined for the document title,');
    $output->writeln('then "=", "-" and "~" for the levels below it.');
    exit(1);
}

if ($fix) {
    exit(0);
}

$output->writeln('Make every overline and every underline exactly as long as its title, or run:');
$output->writeln('  Build/Scripts/runTests.sh -s checkRstSectionAdornments -- --fix');
exit(1);
