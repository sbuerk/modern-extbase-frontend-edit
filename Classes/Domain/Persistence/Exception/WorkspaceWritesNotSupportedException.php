<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\Exception;

/**
 * A write was attempted while a workspace is active.
 *
 * This is not a validation failure and not a permission problem — it is a
 * refusal to perform an operation that would silently do the wrong thing. The
 * Extbase persistence layer issues plain `INSERT`/`UPDATE` statements against
 * the live row (`Typo3DbBackend::addRow()`, `::updateRow()`); it writes no
 * `t3ver_wsid`, no `t3ver_oid`, and it never creates a workspace version. An
 * edit performed while a workspace is selected therefore modifies **published
 * content** while the editor believes they are working in a draft.
 *
 * A `\RuntimeException` rather than an `\InvalidArgumentException`: nothing
 * about the arguments is wrong, the request is refused because of the state the
 * request runs in. A caller that catches this turns it into a response —
 * see `docs/frontend-edit/ajax-transport.md` for the error envelope; the
 * exception code is the `code` of the single error entry.
 *
 * The guard that raises it is {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\WorkspaceGuard}.
 */
final class WorkspaceWritesNotSupportedException extends \RuntimeException {}
