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
use TYPO3\CMS\Core\Resource\ResourceFactory;
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
 * ## File handling has its own two methods, and its own ordering
 *
 * {@see saveProfileImage()} and {@see removeProfileImage()} are separate from
 * {@see saveProfile()} because the image is the one property whose write is not
 * finished when `persistAll()` returns: the previous `sys_file` has to be
 * cleaned up afterwards, under the guard in
 * {@see UnreferencedFileCleanupService}, and the uid it needs can only be read
 * *before* the flush. Both constraints are persistence ordering constraints,
 * which is why they live here and not in the controller.
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
        private UnreferencedFileCleanupService $unreferencedFileCleanupService,
        private ResourceFactory $resourceFactory,
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
     * Persists an uploaded profile image and cleans up the file it replaced.
     *
     * Called after the Extbase upload API has already moved the file into
     * storage and assigned it to the model — which happens in
     * `ActionController::callActionMethod()` at `:466-467`, before the action
     * body runs. There is nothing left to decide about the upload here; what is
     * left is the ordering, and all three parts of it are load-bearing.
     *
     * ## 1. The previous file uid comes from the clean state
     *
     * By the time this method is reached, the in-memory reference already
     * points at the **new** file: on a re-upload the API reuses the existing
     * `FileReference` and calls `setOriginalResource()` on it
     * (`cms-extbase/Classes/Service/FileHandlingService.php:290-292`), which
     * overwrites `uidLocal`
     * (`cms-extbase/Classes/Domain/Model/FileReference.php:37-41`). The old uid
     * survives only in the entity's clean state, memorized by the data mapper
     * after it thawed the object
     * (`cms-extbase/Classes/Persistence/Generic/Mapper/DataMapper.php:157`) and
     * read back with `_getCleanProperty('uidLocal')`
     * (`cms-extbase/Classes/DomainObject/AbstractDomainObject.php:239-242`).
     *
     * For a first upload there is no clean state — the reference was created by
     * `createExtbaseFileReference()` moments ago — and the accessor answers
     * `null`, which is exactly "nothing to clean up".
     *
     * `_getCleanProperty()` is `@internal`. That is a knowing trade-off, and the
     * alternative is worse: the value it returns exists nowhere else in memory,
     * and re-reading `uid_local` from the database before the flush would mean
     * a second query for a value the object already holds. The method has been
     * unchanged since Extbase gained the persistence session and is identical
     * on both supported versions.
     *
     * ## 2. The cleanup runs after `persistAll()`
     *
     * Before the flush the `sys_file_reference` row still carries the old
     * `uid_local`, so {@see UnreferencedFileCleanupService} would count the
     * caller's own reference — and, worse, deleting the file at that moment
     * would take out the very row that is about to be repointed, because
     * `FileDeletionAspect` deletes every reference to a deleted file. After the
     * flush the row points at the new file and the old one is, in the ordinary
     * case, referenced by nobody.
     *
     * ## 3. The in-memory reference is re-resolved from the persisted row
     *
     * The core `FileReference` the upload service built is a synthetic object:
     * it was constructed with `'uid' => 'NEW_…'`
     * (`FileHandlingService.php:440-447`), so `getUid()` on it answers `0` and
     * a response document built from it would report a reference uid the client
     * cannot use. `setOriginalResource()` with a freshly fetched resource
     * replaces it once the row exists. It writes the same `uidLocal` back and
     * therefore leaves nothing dirty behind.
     *
     * @param Profile $profile a persisted profile the caller has established the session owns, carrying the uploaded image
     * @throws WorkspaceWritesNotSupportedException
     */
    public function saveProfileImage(Profile $profile): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $reference = $profile->getImage();
        $previousFileUid = $reference?->_getCleanProperty('uidLocal');
        $previousFileUid = is_int($previousFileUid) ? $previousFileUid : 0;

        $this->profileEditRepository->update($profile);
        $this->persistenceManager->persistAll();

        $referenceUid = (int)($reference?->getUid() ?? 0);
        $currentFileUid = 0;
        if ($reference !== null && $referenceUid > 0) {
            $coreReference = $this->resourceFactory->getFileReferenceObject($referenceUid);
            $reference->setOriginalResource($coreReference);
            $currentFileUid = $coreReference->getOriginalFile()->getUid();
        }

        if ($previousFileUid > 0 && $previousFileUid !== $currentFileUid) {
            $this->unreferencedFileCleanupService->deleteWhenUnreferenced($previousFileUid, $referenceUid);
        }
    }

    /**
     * Clears the profile image and cleans up the file it referenced.
     *
     * Idempotent: a profile without an image is answered by doing nothing, so
     * a client that removes twice gets the same result twice rather than an
     * error about a state it already reached.
     *
     * Three things happen that Extbase does not do by itself, in this order:
     *
     * 1. `image = 0` on the profile row. A nullable domain object property
     *    whose value is `null` is written as `0`
     *    (`cms-extbase/Classes/Persistence/Generic/Backend.php:911-924`).
     * 2. The `sys_file_reference` row is soft deleted. Extbase leaves it
     *    behind: `Backend::persistObject()` only queues a child that is still
     *    the property value (`:290-297`), and the detached one is reachable from
     *    nothing. `\TYPO3\CMS\Core\Resource\FileReference::delete()` writes the
     *    `deleted` flag (`cms-core/Classes/Resource/FileReference.php:364-388`),
     *    which is why the count in the cleanup service uses a
     *    `DeletedRestriction` rather than looking for an absent row.
     * 3. The `sys_file` is deleted, if and only if nothing else points at it.
     *
     * The **guarded** path is used here too, deliberately. The built-in
     * `@delete` flow does the same three things unconditionally
     * (`FileHandlingService.php:344-346`) and would destroy the references of
     * records this extension does not own.
     *
     * @param Profile $profile a persisted profile the caller has established the session owns
     * @throws WorkspaceWritesNotSupportedException
     */
    public function removeProfileImage(Profile $profile): void
    {
        $this->workspaceGuard->assertWritesAllowed();

        $reference = $profile->getImage();
        if ($reference === null) {
            return;
        }

        // Read before the property is cleared: after the flush the object graph
        // no longer knows which file this profile used to point at. Nothing has
        // overwritten the live value here — unlike in saveProfileImage() — so
        // the resolved FAL objects are the right source and no clean state is
        // needed.
        $coreReference = $reference->getOriginalResource();
        $fileUid = $coreReference->getOriginalFile()->getUid();
        $referenceUid = (int)($reference->getUid() ?? 0);

        $profile->setImage(null);
        $this->profileEditRepository->update($profile);
        $this->persistenceManager->persistAll();

        $coreReference->delete();

        $this->unreferencedFileCleanupService->deleteWhenUnreferenced($fileUid, $referenceUid);
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
