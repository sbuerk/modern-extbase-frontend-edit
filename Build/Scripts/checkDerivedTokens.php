<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../../.Build/vendor/autoload.php';
require_once __DIR__ . '/DerivedTokenChecker.php';

/**
 * Quality gate over custom properties that read other custom properties. The
 * rule lives in "DerivedTokenChecker.php"; this file adds the file reading and
 * the reporting.
 *
 *   Build/Scripts/runTests.sh -s checkDerivedTokens
 *
 * ## What it is for
 *
 * One token declared on ":root" as "var(--another)" resolved against the light
 * palette and inherited that literal into the dark scheme, so every focused
 * control on a dark page drew the light accent at 2.80:1. The fix was two lines;
 * finding it took measuring a colour nobody had thought to measure, and the
 * *class* of defect survives the fix — the next token declared that way has it
 * again, and the declaration gives no hint, because it reads exactly like one
 * that works.
 *
 * ## Why every stylesheet is read at once
 *
 * They are "@import"ed into one document and form one cascade, so a token
 * declared in "_variables.css" is redeclared in "_variables.css" and read in
 * "_plugin.css". Checking a file at a time would answer a question no browser
 * ever asks.
 *
 * The extension's own shipped stylesheet is included for the same reason: it is
 * on the same page. It declares nothing on the root, so it contributes no
 * findings — which is the correct result and worth having asserted rather than
 * assumed, because moving its token block to ":root" would be an easy and
 * entirely reasonable looking change to make.
 *
 * ## What it cannot see
 *
 * Staleness between two non-root contexts, which needs to know which element is
 * an ancestor of which and cannot be decided from selector text. See the class
 * docblock for why that is a deliberate limit rather than an omission.
 */
$output = new ConsoleOutput();
$root = dirname(__DIR__, 2);

$themeCssDirectory = $root . '/packages/dev-site/Resources/Public/Css';
$surfacePath = $root . '/Resources/Public/Css/frontend/frontend-edit.css';

if (!is_dir($themeCssDirectory) || !is_file($surfacePath)) {
    $output->writeln('<error>Stylesheets not found. Expected:</error>');
    $output->writeln('  ' . substr($themeCssDirectory, strlen($root) + 1));
    $output->writeln('  ' . substr($surfacePath, strlen($root) + 1));
    exit(1);
}

$stylesheets = [];
$finder = new Finder();
$finder->files()->name('*.css')->in($themeCssDirectory)->sortByName();
foreach ($finder as $file) {
    $stylesheets[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
}
$stylesheets[substr($surfacePath, strlen($root) + 1)] = (string)file_get_contents($surfacePath);

$result = (new DerivedTokenChecker())->check($stylesheets);

if ($result['stale'] !== []) {
    $output->writeln('<error>Tokens that cannot follow the scheme they are switched into:</error>');
    $output->writeln('');
    foreach ($result['stale'] as $finding) {
        $output->writeln(sprintf(
            '  %s reads %s, which "%s" redefines — and %s is not redefined there.',
            $finding['token'],
            $finding['reads'],
            $finding['context'],
            $finding['token'],
        ));
    }
    $output->writeln('');
    $output->writeln('A custom property is substituted at computed value time on the element that');
    $output->writeln('declares it. Declared on the root, the one above resolves against the root\'s');
    $output->writeln('value and inherits the *result* downwards, so redefining what it reads further');
    $output->writeln('down changes nothing for it.');
    $output->writeln('');
    $output->writeln('Restate the token in every context that redefines what it reads, or declare it');
    $output->writeln('somewhere the scheme has already been decided.');
    $output->writeln('');
    $output->writeln('This fails silently in a browser and is invisible in a screenshot: the value is');
    $output->writeln('valid, it is simply the other scheme\'s.');
    exit(1);
}

$output->writeln(sprintf(
    '<info>Checked %d root declared tokens that read another token, none of them goes stale in a scheme.</info>',
    count($result['derived'])
));
exit(0);
