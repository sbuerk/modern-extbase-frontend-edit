<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Configuration;

use SBUERK\ModernExtbaseFrontendEdit\Dto\ComponentConfiguration;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * Turns the installation's configuration into what the surface is handed.
 *
 * Stateless, as every service here is: it holds the two core services it was
 * constructed with and nothing else, and calling it twice with the same
 * `$GLOBALS` returns equal objects.
 *
 * ## Where the configuration lives, and why there
 *
 * `$GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit']`, with two
 * sub-keys:
 *
 *     $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit'] = [
 *         'icons' => [
 *             // action name => icon identifier
 *             'edit' => 'actions-open',
 *         ],
 *         'classes' => [
 *             // element type => additional CSS classes
 *             'button' => 'button',
 *             'buttonPrimary' => 'button-primary',
 *             'control' => 'form-control',
 *         ],
 *     ];
 *
 * This is deliberately the **low level** seam and not a finished configuration
 * story. `TYPO3_CONF_VARS` is global, so it cannot differ per site, per page or
 * per plugin instance, and a real implementation would want at least site
 * settings. It is chosen because it is the one place that is available in every
 * context this extension renders in - including a frontend sub-request with no
 * site settings resolved yet - and because it costs no schema. When it grows
 * up, this factory is the only class that has to learn where to read from: the
 * rest of the extension consumes {@see ComponentConfiguration}.
 *
 * ## Unknown keys are ignored, missing ones fall back
 *
 * Both maps are merged over the defaults rather than replacing them, so an
 * installation that renames one icon keeps the other twelve. A key that names
 * no known action or element type is dropped: the surface renders a fixed set
 * of both, and silently carrying an unknown one into the document would make a
 * typo look like it worked.
 *
 * ## Icons are resolved here, not in the browser
 *
 * An identifier means nothing client side, so the markup travels instead - see
 * {@see ComponentConfiguration}. An identifier that is not registered resolves
 * to core's own "missing icon" rather than to an exception, which is the right
 * failure: a mistyped identifier in an integrator's configuration should make
 * one button look wrong, not take the surface down.
 */
final readonly class ComponentConfigurationFactory
{
    /**
     * The action names the surface draws, and the icon each uses by default.
     *
     * The keys are the contract with the JavaScript: `frontend-edit.ts` looks an
     * action up by exactly these names. The values are identifiers, so all of
     * them can be repointed without touching this file.
     *
     * @var array<string, string>
     */
    private const DEFAULT_ICONS = [
        'edit' => 'modern-extbase-frontend-edit-edit',
        'editRecord' => 'modern-extbase-frontend-edit-edit-record',
        'apply' => 'modern-extbase-frontend-edit-apply',
        'cancel' => 'modern-extbase-frontend-edit-cancel',
        'add' => 'modern-extbase-frontend-edit-add',
        'remove' => 'modern-extbase-frontend-edit-remove',
        'chooseImage' => 'modern-extbase-frontend-edit-choose-image',
        'moveUp' => 'modern-extbase-frontend-edit-move-up',
        'moveDown' => 'modern-extbase-frontend-edit-move-down',
        'moveToTop' => 'modern-extbase-frontend-edit-move-to-top',
        'moveToBottom' => 'modern-extbase-frontend-edit-move-to-bottom',
        'hide' => 'modern-extbase-frontend-edit-hide',
        'show' => 'modern-extbase-frontend-edit-show',
    ];

    /**
     * The element types a project may add classes to, and nothing by default.
     *
     * Empty on purpose. The surface always carries its own `frontend-edit-*`
     * classes, which are what its stylesheet and the acceptance suite address;
     * these are *additional*, and a default here would be this extension
     * guessing at the class names of a design system it cannot see.
     *
     * @var array<string, string>
     */
    private const DEFAULT_CLASSES = [
        'record' => '',
        'child' => '',
        'field' => '',
        'label' => '',
        'value' => '',
        'control' => '',
        'button' => '',
        'buttonPrimary' => '',
        'buttonDanger' => '',
        'buttonIconOnly' => '',
        'filePicker' => '',
        'errors' => '',
        'state' => '',
    ];

    public function __construct(
        private IconFactory $iconFactory,
        private IconRegistry $iconRegistry,
    ) {}

    public function create(): ComponentConfiguration
    {
        /** @var array{icons?: mixed, classes?: mixed} $configured */
        $configured = $GLOBALS['TYPO3_CONF_VARS']['modern_extbase_frontend_edit'] ?? [];

        return new ComponentConfiguration(
            $this->resolveIcons($this->stringMap($configured['icons'] ?? null, self::DEFAULT_ICONS)),
            $this->stringMap($configured['classes'] ?? null, self::DEFAULT_CLASSES),
        );
    }

    /**
     * Merges configured values over the defaults, keeping only known keys.
     *
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    private function stringMap(mixed $configured, array $defaults): array
    {
        if (!is_array($configured)) {
            return $defaults;
        }

        $merged = $defaults;
        foreach ($configured as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults) || !is_string($value)) {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, string> $identifiers
     * @return array<string, string>
     */
    private function resolveIcons(array $identifiers): array
    {
        $markup = [];
        foreach ($identifiers as $action => $identifier) {
            $markup[$action] = $this->inlineSvg($identifier);
        }

        return $markup;
    }

    /**
     * The sanitised inline SVG of one identifier.
     *
     * `'inline'` is the alternative markup identifier every SVG icon provider
     * registers. The constant naming it lives on `AbstractSvgIconProvider`,
     * which is `@internal`, so the literal is written out rather than importing
     * a class this extension is not entitled to depend on.
     *
     * An unregistered identifier is answered with an empty string rather than
     * with core's missing-icon markup: that markup is a sprite reference, and
     * shipping one into the frontend is exactly what
     * `Configuration/Icons.php` explains this extension avoids. A button with no
     * glyph still carries its label, so the surface stays usable.
     */
    private function inlineSvg(string $identifier): string
    {
        if (!$this->iconRegistry->isRegistered($identifier)) {
            return '';
        }

        return $this->iconFactory->getIcon($identifier, IconSize::SMALL)->getAlternativeMarkup('inline');
    }
}
