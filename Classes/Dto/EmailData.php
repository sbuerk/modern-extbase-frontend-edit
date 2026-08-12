<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Dto;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The editable fields of an {@see Email}, as they arrive in a JSON payload.
 *
 * Everything {@see ProfileData} documents applies here unchanged, and
 * {@see AddressData} explains why a 1:n child payload carries no identity of
 * its own. `#[Exclude]` is load-bearing rather than decorative — a
 * container-known DTO is answered by `ObjectConverter` with a container-built
 * instance whose submitted values are all silently dropped.
 *
 * The `Email` model carries no `#[Validate]` on its `email` property for the
 * same reason no DTO does: the attribute has no version neutral spelling. The
 * rule lives in `Validation\EmailRuleSet` instead.
 */
#[Exclude]
final readonly class EmailData
{
    /**
     * The `type` default mirrors the TCA default of the column, which is pinned
     * to `DEFAULT 'others'` in `ext_tables.sql`. The accepted set lives in
     * `Validation\EmailRuleSet`, not here: a default is a value, an accepted
     * set is a rule.
     */
    public function __construct(
        public string $type = 'others',
        public string $email = '',
    ) {}

    /**
     * Builds the object from a decoded JSON payload, reading only known keys.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: self::stringValue($data, 'type', 'others'),
            email: self::stringValue($data, 'email'),
        );
    }

    /**
     * Reads one key as a string — see {@see ProfileData::stringValue()}.
     *
     * @param array<string, mixed> $data
     */
    private static function stringValue(array $data, string $key, string $absentValue = ''): string
    {
        if (!array_key_exists($key, $data)) {
            return $absentValue;
        }

        return is_string($data[$key]) ? $data[$key] : '';
    }
}
