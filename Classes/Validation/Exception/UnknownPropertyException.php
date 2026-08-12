<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Validation\Exception;

/**
 * A partial save addressed a field the rule set does not declare.
 *
 * This is deliberately an exception and not an entry in the returned `Result`.
 * An unknown field name is not a value that failed a rule — there is no rule,
 * and there is no property. Reporting it as a field error would place it in the
 * same structure a client uses to decorate its form, and would leave a careless
 * caller free to treat the save as "validated with errors" and carry on.
 *
 * A caller that reaches this has sent something the endpoint does not offer, so
 * the answer is a rejected request rather than a validation response.
 */
final class UnknownPropertyException extends \InvalidArgumentException {}
