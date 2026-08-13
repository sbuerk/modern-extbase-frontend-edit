<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Configuration\ComponentConfigurationFactory;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;

/**
 * The seam a project configures the surface through.
 *
 * A functional test rather than a unit test, and not for convenience: the whole
 * point of the factory is that it resolves an icon *identifier* through TYPO3's
 * `IconRegistry`, and a unit test would have to mock the one collaborator whose
 * real behaviour is the thing worth asserting.
 *
 * `$GLOBALS['TYPO3_CONF_VARS']` is restored by the testing framework between
 * tests, so writing into it here is local to one test.
 */
final class ComponentConfigurationFactoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function everyActionResolvesToInlineSvgOutOfTheBox(): void
    {
        $configuration = $this->get(ComponentConfigurationFactory::class)->create();

        $this->assertNotSame([], $configuration->icons());
        foreach ($configuration->icons() as $action => $markup) {
            $this->assertStringContainsString('<svg', $markup, sprintf('action "%s"', $action));
            $this->assertStringContainsString('currentColor', $markup, sprintf('action "%s"', $action));
        }
    }

    #[Test]
    public function anActionCanBePointedAtADifferentIcon(): void
    {
        $before = $this->get(ComponentConfigurationFactory::class)->create()->icons()['edit'] ?? '';

        $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']['icons']['edit']
            = 'modern-extbase-frontend-edit-remove';

        $after = $this->get(ComponentConfigurationFactory::class)->create()->icons()['edit'] ?? '';

        $this->assertNotSame('', $before);
        $this->assertNotSame($before, $after, 'repointing "edit" changed nothing');
        // And it really is the other glyph, not merely a different string.
        $remove = $this->get(ComponentConfigurationFactory::class)->create()->icons()['remove'] ?? '';
        $this->assertSame($remove, $after);
    }

    /**
     * The failure an integrator is most likely to produce.
     */
    #[Test]
    public function anUnregisteredIdentifierCostsOneGlyphAndNotTheSurface(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']['icons']['edit'] = 'no-such-icon-at-all';

        $configuration = $this->get(ComponentConfigurationFactory::class)->create();

        $this->assertSame('', $configuration->icons()['edit'] ?? null);
        // Every other action is untouched, which is what "costs one glyph" means.
        $this->assertStringContainsString('<svg', $configuration->icons()['cancel'] ?? '');
    }

    #[Test]
    public function additionalClassesAreConfiguredPerElementType(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']['classes'] = [
            'button' => 'button',
            'buttonPrimary' => 'button-primary',
        ];

        $classes = $this->get(ComponentConfigurationFactory::class)->create()->classes();

        $this->assertSame('button', $classes['button'] ?? null);
        $this->assertSame('button-primary', $classes['buttonPrimary'] ?? null);
        // Unconfigured types keep their empty default rather than disappearing,
        // so the client side never has to distinguish "absent" from "empty".
        $this->assertSame('', $classes['control'] ?? null);
    }

    #[Test]
    public function anUnknownKeyIsIgnoredRatherThanCarriedIntoTheDocument(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit'] = [
            'icons' => ['notAnAction' => 'modern-extbase-frontend-edit-add'],
            'classes' => ['notAnElement' => 'whatever'],
        ];

        $configuration = $this->get(ComponentConfigurationFactory::class)->create();

        $this->assertArrayNotHasKey('notAnAction', $configuration->icons());
        $this->assertArrayNotHasKey('notAnElement', $configuration->classes());
        // A typo must not silently take the default with it.
        $this->assertStringContainsString('<svg', $configuration->icons()['edit'] ?? '');
    }

    #[Test]
    public function theWholeConfigurationSurvivesJsonEncoding(): void
    {
        $encoded = json_encode($this->get(ComponentConfigurationFactory::class)->create());

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('icons', $decoded);
        $this->assertArrayHasKey('classes', $decoded);
        // The property that makes an endpoint possible without a second data
        // structure: what the DTO serialises is the complete configuration.
        $this->assertSame(
            array_keys($this->get(ComponentConfigurationFactory::class)->create()->icons()),
            array_keys($decoded['icons']),
        );
    }
}
