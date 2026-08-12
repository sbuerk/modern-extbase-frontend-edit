<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Dependency injection wiring of the fixture extension.
 *
 * It registers the classes of the extension and nothing else — the one service
 * publishes itself with a Symfony attribute on the class, exactly as a real
 * extension of this repository does.
 */
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load(
        'TESTS\\WorkspaceFixture\\',
        __DIR__ . '/../Classes/*',
    );
};
