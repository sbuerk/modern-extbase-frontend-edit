<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\Exception\WorkspaceWritesNotSupportedException;
use TYPO3\CMS\Core\Context\Context;

/**
 * Refuses every write while a workspace is active.
 *
 * ## The guard is functional, not defensive
 *
 * Extbase persistence is entirely workspace blind. `Typo3DbBackend::addRow()`
 * and `Typo3DbBackend::updateRow()` issue plain DBAL statements against the live
 * row — no `t3ver_wsid`, no `t3ver_oid`, no versioning, no `DataHandler`. There
 * is no code path in which editing inside a workspace produces a workspace
 * version. What it produces is a modified live record, published immediately,
 * while the editor is looking at a draft workspace and expects the opposite.
 *
 * Removing this guard therefore does not "allow workspace editing". It silently
 * corrupts published content, which is why the refusal is part of the feature
 * and is documented as a limitation rather than hidden — see
 * `docs/frontend-edit/persistence-and-sorting.md`.
 *
 * ## Why `Context`, and what the default actually covers
 *
 * The workspace is read from the injected {@see Context}, never from
 * `$GLOBALS`. Core reads the same signal in the same way — `Typo3DbQueryParser`
 * asks for `workspace/isOffline`, `PageRepository` for `workspace/id`.
 *
 * The `true` default of {@see Context::getPropertyFromAspect()} is worth being
 * precise about, because it is easy to read as a fail-open hole and it is not
 * the one it looks like:
 *
 * - A **missing aspect** cannot happen. `Context::hasAspect()` answers `true`
 *   for `workspace` unconditionally, and `Context::getAspect()` lazily
 *   instantiates a default `WorkspaceAspect()` — whose `$workspaceId` is `0`,
 *   i.e. live. The `AspectNotFoundException` branch is unreachable for this
 *   aspect name.
 * - The default is only consulted for an **unknown property** on an aspect that
 *   *is* registered. `WorkspaceAspect::get()` handles `id`, `isLive` and
 *   `isOffline`, so reaching the default means somebody replaced the aspect with
 *   a class that does not implement the documented contract.
 *
 * The comparison below is `=== true` rather than a truthiness check, so a
 * replacement aspect returning a non-boolean does not pass as "live" by
 * accident.
 *
 * The service is stateless: it holds the shared `Context` and nothing derived
 * from a request. The workspace is read on every call rather than cached,
 * because a cached answer would be served to the next caller in the same
 * request.
 */
final readonly class WorkspaceGuard
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * Whether writes are permitted in the current request.
     *
     * `WorkspaceAspect::isLive()` is `$this->workspaceId === 0`, and the aspect
     * class is byte identical on TYPO3 v13 and v14, so this needs no version
     * handling.
     */
    public function areWritesAllowed(): bool
    {
        return $this->context->getPropertyFromAspect('workspace', 'isLive', true) === true;
    }

    /**
     * Throws unless writes are permitted in the current request.
     *
     * Called at the entry of every public write method of
     * {@see ProfilePersistenceService}, before anything touches the object
     * graph. Asserting at the boundary that performs the write — rather than
     * only in the controller — is deliberate: the controller's copy answers the
     * request cleanly, this one is what makes the rule impossible to bypass by
     * adding a second caller.
     *
     * @throws WorkspaceWritesNotSupportedException
     */
    public function assertWritesAllowed(): void
    {
        if ($this->areWritesAllowed()) {
            return;
        }

        // No reflected input in the message: it is rendered into a response
        // body, and the workspace id is not the caller's business anyway.
        throw new WorkspaceWritesNotSupportedException(
            'Editing is not available while a workspace is active.',
            1786493001
        );
    }
}
