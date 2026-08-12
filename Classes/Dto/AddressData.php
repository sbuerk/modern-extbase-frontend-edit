<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Dto;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The editable fields of an {@see Address}, as they arrive in a JSON payload.
 *
 * Everything {@see ProfileData} documents applies here unchanged: the
 * constructor parameter list is the whitelist, there is no `uid`, no `pid` and
 * no `sys_language_uid`, and `#[Exclude]` is load-bearing rather than
 * decorative — a container-known DTO is answered by `ObjectConverter` with a
 * container-built instance whose submitted values are all silently dropped.
 *
 * ## Why the child carries no identity
 *
 * An address is a 1:n child, so a full profile submit could plausibly ship a
 * list of them and expect each to be matched to an existing row by `uid`. It
 * does not, because a client supplied row id is exactly the insecure direct
 * object reference this design exists to remove: it would be used to *seed* a
 * lookup rather than to *filter* an already owned set.
 *
 * The row an `AddressData` updates is therefore identified out of band — from
 * the addressed resource, resolved against the profiles the session owns — and
 * this object only ever carries the values to write. That is also why it is a
 * payload of its own rather than a nested property of {@see ProfileData}.
 */
#[Exclude]
final readonly class AddressData
{
    /**
     * The `type` default mirrors the TCA default of the column, which is pinned
     * to `DEFAULT 'others'` in `ext_tables.sql`. The accepted set lives in
     * `Validation\AddressRuleSet`, not here: a default is a value, an accepted
     * set is a rule.
     */
    public function __construct(
        public string $type = 'others',
        public string $line1 = '',
        public string $line2 = '',
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
            line1: self::stringValue($data, 'line1'),
            line2: self::stringValue($data, 'line2'),
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
