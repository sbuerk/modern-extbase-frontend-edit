<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Dto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Everything the client side surface needs that is not a value of the record.
 *
 * Two maps, and nothing else. What they hold is the answer to "which glyph does
 * this action draw" and "which CSS classes does this kind of element carry",
 * both of which used to be compiled into the JavaScript and were therefore
 * unreachable for a project: replacing an icon meant editing a TypeScript module
 * and rebuilding the assets of an extension the project does not own.
 *
 * ## Why the icons arrive as markup rather than as identifiers
 *
 * The obvious shape is `{"edit": "modern-extbase-frontend-edit-edit"}` — smaller,
 * and it keeps the indirection visible. It cannot work. The surface is rendered
 * in the browser, and an identifier is meaningless there: resolving it needs
 * `IconRegistry`, which is PHP, and the only thing that could turn one into a
 * glyph client side is a request per icon to an endpoint that does not exist and
 * should not.
 *
 * The identifier is therefore resolved on the server and the **markup** travels.
 * The indirection is not lost, it just happens one layer earlier — see
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Configuration\ComponentConfigurationFactory}.
 *
 * ## Why it is `JsonSerializable`
 *
 * It travels today in a `data-config` attribute, rendered with the rest of the
 * document. It is written to be servable from an endpoint without changing
 * anything: `json_encode()` of this object is the complete configuration, so an
 * `ajax` route returning it is a controller action and no new data structure.
 * The attribute is preferred for now for the reason the rest of the surface
 * uses attributes — a document that arrives complete cannot render a surface
 * that is briefly wrong while a second request is in flight.
 *
 * `#[Exclude]` because this is data. A container known DTO is answered by
 * Extbase's `ObjectConverter` with a container built instance, and every
 * submitted value is silently dropped.
 */
#[Exclude]
final readonly class ComponentConfiguration implements \JsonSerializable
{
    /**
     * @param array<string, string> $icons Action name to inline SVG markup.
     * @param array<string, string> $classes Element type to additional CSS classes.
     */
    public function __construct(
        private array $icons,
        private array $classes,
    ) {}

    /**
     * @return array<string, string>
     */
    public function icons(): array
    {
        return $this->icons;
    }

    /**
     * @return array<string, string>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * @return array{icons: array<string, string>, classes: array<string, string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'icons' => $this->icons,
            'classes' => $this->classes,
        ];
    }
}
