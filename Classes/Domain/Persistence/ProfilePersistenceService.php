<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence\Exception\WorkspaceWritesNotSupportedException;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\EmailEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The single write path of the frontend edit feature.
 *
 * Every endpoint that changes a profile or one of its children goes through
 * this service, and it is the only place in the extension that calls
 * `update()`, `remove()` or `persistAll()`. Concentrating that is what makes
 * the two rules below hold everywhere rather than in most places: the workspace
 * refusal and the explicit orphan deletion.
 *
 * The mechanics of the object graph — reordering, the child pid and the orphan
 * diff — live in {@see ChildCollectionSynchronizer}, with the source citations
 * that justify each of them. This class is the persistence decisions: what is
 * marked changed, what is deleted, and when the unit of work is flushed.
 *
 * ## Ownership is not checked here
 *
 * By design. The caller resolves the profile from the session through
 * `Security\ProfileOwnershipResolverInterface` and the owner constrained
 * finders of the edit repositories, and hands the result in. Every `$owned`
 * argument below **is** that resolved set. Re-checking here would put the
 * authorization rule in two places, and two copies of a security rule drift —
 * the copy nobody reads drifts first, and it is the one that stays permissive.
 * A `findByUid()` on a request supplied uid appears nowhere in this namespace.
 *
 * ## What is *not* done, and why
 *
 * - **New children are never added to a repository.** `persistObjectStorage()`
 *   inserts them itself, through `insertObject($child, $parent)`, which is what
 *   writes the parent pointer. An additional `$repository->add($child)` would
 *   register the child as an aggregate root as well, and it would be inserted a
 *   second time without a parent.
 * - **No `#[Cascade('remove')]`.** It has no spelling that is valid on TYPO3
 *   v13 and free of deprecations on v14, it never covers a replaced `HAS_ONE`
 *   relation, and it is skipped without any error for a `#[Lazy]` property.
 *   Manual removal is the design, not a workaround —
 *   `docs/frontend-edit/persistence-and-sorting.md`.
 * - **No transaction.** `persistAll()` is not one, and the Extbase storage
 *   backend offers none. A failure part way through leaves a partially written
 *   aggregate. That is accepted for this editing surface and named rather than
 *   pretended away; it is also why every write method flushes exactly once, so
 *   the window is as small as the API allows.
 * - **No file handling.** Replacing or clearing the image reference is the
 *   upload path's concern and needs the file cleanup rule from
 *   `docs/frontend-edit/image-handling.md`.
 *
 * ## Order of operations
 *
 * `remove()` before `persistAll()`, always. `Backend::commit()` runs
 * `persistObjects()` first and `processDeletedObjects()` afterwards, so within
 * one flush the detach of an orphan (parent pointer `0`, sorting `0`) is written
 * before its `deleted = 1`. Both statements hit the same row and the end state
 * is the same either way, but the order is worth knowing when reading a query
 * log.
 *
 * The service is stateless: it holds collaborators and nothing derived from a
 * request. Every record it operates on is an argument.
 */
final readonly class ProfilePersistenceService
{
    public function __construct(
        private WorkspaceGuard $workspaceGuard,
        private ChildCollectionSynchronizer $childCollectionSynchronizer,
        private ProfileEditRepository $profileEditRepository,
        private AddressEditRepository $addressEditRepository,
        private EmailEditRepository $emailEditRepository,
        private PersistenceManagerInterface $persistenceManager,
    ) {}

    /**
     * Persists property changes on the profile record itself.
     *
     * This is the full save without children, the partial save of a single
     * profile field, and the visibility toggle — in all three the mapper has
     * already mutated the model and there is nothing left to decide.
     *
     * @param Profile $profile a persisted profile the caller has established the session owns
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveProfile(Profile $profile): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $this->profileEditRepository->update($profile);
        $this->persistenceManager->persistAll();
    }

    /**
     * Persists property changes on a single address record.
     *
     * Used by the partial save of one child field and by the visibility toggle.
     * The parent relation is untouched: a property change on a contained child
     * does not mark the parent's storage dirty — `ObjectStorage::_isDirty()` is
     * set by `attach()`/`detach()` only — so nothing here can disturb the
     * sorting.
     *
     * @param Address $address an address the caller has established belongs to an owned profile
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveAddress(Address $address): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $this->addressEditRepository->update($address);
        $this->persistenceManager->persistAll();
    }

    /**
     * Persists property changes on a single e-mail record — see
     * {@see saveAddress()}.
     *
     * @param Email $email an e-mail the caller has established belongs to an owned profile
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveEmail(Email $email): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $this->emailEditRepository->update($email);
        $this->persistenceManager->persistAll();
    }

    /**
     * Writes the address collection of a profile: order, new children, and the
     * deletion of the ones that dropped out.
     *
     * This is what the add, remove and reorder endpoints call. All three are the
     * same operation from here — the caller states the intended set, and the
     * difference to the owned set is what gets created and deleted.
     *
     * @param Profile $profile a persisted profile the caller has established the session owns
     * @param ObjectStorage<Address> $intended the intended set in the intended order, from the mapper
     * @param list<Address> $owned the owner constrained set of persisted addresses, from the edit repository
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveAddresses(Profile $profile, ObjectStorage $intended, array $owned): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $this->applyAddresses($profile, $intended, $owned);

        $this->profileEditRepository->update($profile);
        $this->persistenceManager->persistAll();
    }

    /**
     * Writes the e-mail collection of a profile — see {@see saveAddresses()}.
     *
     * @param Profile $profile a persisted profile the caller has established the session owns
     * @param ObjectStorage<Email> $intended the intended set in the intended order, from the mapper
     * @param list<Email> $owned the owner constrained set of persisted e-mails, from the edit repository
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveEmails(Profile $profile, ObjectStorage $intended, array $owned): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $this->applyEmails($profile, $intended, $owned);

        $this->profileEditRepository->update($profile);
        $this->persistenceManager->persistAll();
    }

    /**
     * @param ObjectStorage<Address> $intended
     * @param list<Address> $owned
     */
    private function applyAddresses(Profile $profile, ObjectStorage $intended, array $owned): void
    {
        $orphans = $this->childCollectionSynchronizer->synchronize(
            $profile,
            $profile->getAddresses(),
            $intended,
            $owned,
        );

        foreach ($orphans as $orphan) {
            // Explicit, per child. Detaching alone leaves the row behind with a
            // cleared parent pointer and `sorting = 0`; `remove()` soft deletes
            // it, because the table has a `ctrl.delete` column.
            $this->addressEditRepository->remove($orphan);
        }
    }

    /**
     * @param ObjectStorage<Email> $intended
     * @param list<Email> $owned
     */
    private function applyEmails(Profile $profile, ObjectStorage $intended, array $owned): void
    {
        $orphans = $this->childCollectionSynchronizer->synchronize(
            $profile,
            $profile->getEmails(),
            $intended,
            $owned,
        );

        foreach ($orphans as $orphan) {
            $this->emailEditRepository->remove($orphan);
        }
    }
}
