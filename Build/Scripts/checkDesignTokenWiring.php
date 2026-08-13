<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../../.Build/vendor/autoload.php';
require_once __DIR__ . '/DesignTokenWiringChecker.php';

/**
 * Quality gate over the design tokens of the editing surface. The rules live in
 * "DesignTokenWiringChecker.php"; this file adds the file reading and the
 * reporting.
 *
 *   Build/Scripts/runTests.sh -s checkDesignTokenWiring
 *
 * ## What it is for
 *
 * The extension declares a token with a default and the development site package
 * declares it again in terms of the theme's own scale, so that there is one
 * scale rather than two that agree by coincidence. The mechanism has one failure
 * mode and it is invisible: a token that is added to the surface and not mapped
 * keeps working, keeps looking right for as long as the two values happen to
 * agree, and drifts the day one of them is edited. Nothing else in this
 * repository can see that. A browser cannot — an unmapped token is a valid
 * declaration. The visual suite cannot — the pixels are identical until the
 * drift happens, and by then the baseline has been re-recorded around it.
 *
 * ## Why it checks the fixture, and why that is the point
 *
 * "packages/dev-site" is a test fixture, and a gate that fails because a fixture
 * is out of step reads like a gate pointed at the wrong tree. It is pointed at
 * exactly the right one: the fixture is the only place in this repository where
 * an integrator's side of the contract is written down, so it is the only place
 * the contract can be checked at all. A surface token with nothing on the other
 * end of it is a token no integrator has ever been shown how to set.
 *
 * ## What it cannot see
 *
 * That a mapping is the *right* one. "--frontend-edit-radius-lg" pointing at
 * "--radius-lg" instead of "--radius" is wired, is a 4 pixel visual change, and
 * is a question for the image suites and a person. This gate answers "does every
 * value come from one place", not "is it the correct value".
 */
$output = new ConsoleOutput();
$root = dirname(__DIR__, 2);

$surfacePath = $root . '/Build/Sources/Css/frontend/frontend-edit.css';
$mappingPath = $root . '/packages/dev-site/Resources/Public/Css/_plugin.css';

foreach ([$surfacePath, $mappingPath] as $required) {
    if (!is_file($required)) {
        $output->writeln(sprintf('<error>Not found: %s</error>', substr($required, strlen($root) + 1)));
        exit(1);
    }
}

// Every other stylesheet of the theme, so a mapping that names a token the theme
// does not have is caught. "_plugin.css" is excluded because it is the file
// being checked: a mapping may not satisfy itself.
$themeCss = [];
$finder = new Finder();
$finder->files()
    ->name('*.css')
    ->notName('_plugin.css')
    ->in(dirname($mappingPath))
    ->sortByName();
foreach ($finder as $file) {
    $themeCss[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
}

$result = (new DesignTokenWiringChecker())->check(
    (string)file_get_contents($surfacePath),
    (string)file_get_contents($mappingPath),
    $themeCss
);

$failed = false;

if ($result['undeclared'] !== []) {
    $failed = true;
    $output->writeln('<error>Tokens the surface reads and never declares:</error>');
    foreach ($result['undeclared'] as $token) {
        $output->writeln('  ' . $token);
    }
    $output->writeln('');
    $output->writeln('Declare each of them in the token block of the extension stylesheet. A token');
    $output->writeln('that exists only as a "var()" fallback is a token a site cannot find and');
    $output->writeln('cannot be wired to anything.');
    $output->writeln('');
}

if ($result['unwired'] !== []) {
    $failed = true;
    $output->writeln('<error>Tokens with no single source:</error>');
    foreach ($result['unwired'] as $token) {
        $output->writeln(sprintf('  %s: %s', $token, $result['tokens'][$token]));
    }
    $output->writeln('');
    $output->writeln('Map each one onto the theme in:');
    $output->writeln('  packages/dev-site/Resources/Public/Css/_plugin.css');
    $output->writeln('A token is also accepted when it derives from another token that is mapped, or');
    $output->writeln('when its default is "inherit". Anything else is a value in two files.');
    $output->writeln('');
}

if ($result['literal'] !== []) {
    $failed = true;
    $output->writeln('<error>Mappings that repeat a value instead of naming a theme token:</error>');
    foreach ($result['literal'] as $token => $value) {
        $output->writeln(sprintf('  %s: %s', $token, $value));
    }
    $output->writeln('');
    $output->writeln('This is the failure the gate exists for: it agrees with the theme today,');
    $output->writeln('nothing connects the two, and they disagree in silence the day either moves.');
    $output->writeln('');
}

if ($result['stale'] !== []) {
    $failed = true;
    $output->writeln('<error>Mappings for tokens the surface no longer has:</error>');
    foreach ($result['stale'] as $token) {
        $output->writeln('  ' . $token);
    }
    $output->writeln('');
}

if ($result['unknownThemeTokens'] !== []) {
    $failed = true;
    $output->writeln('<error>Theme tokens a mapping names that the theme does not declare:</error>');
    foreach ($result['unknownThemeTokens'] as $token => $surfaceToken) {
        $output->writeln(sprintf('  %s, named by %s', $token, $surfaceToken));
    }
    $output->writeln('');
    $output->writeln('A custom property that does not resolve fails silently: the declaration is');
    $output->writeln('invalid at computed value time and the page still renders.');
    $output->writeln('');
}

if ($failed) {
    exit(1);
}

$output->writeln(sprintf(
    '<info>Checked %d design tokens of the editing surface, every one of them has a single source.</info>',
    count($result['tokens'])
));
exit(0);
