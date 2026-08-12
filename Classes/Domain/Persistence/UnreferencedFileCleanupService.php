<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Persistence;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Deletes a `sys_file` that nothing points at any more — and refuses to when
 * anything still does.
 *
 * The Extbase upload API leaves the previous file behind on a re-upload: it
 * creates a new `sys_file` record and a new physical file, repoints the
 * existing `sys_file_reference` at it, and does nothing about the old one
 * (`cms-extbase/Classes/Service/FileHandlingService.php:284-294`). Nothing in
 * either core version collects those. `ext:form` got a cleanup command for its
 * own upload folders in v14.2 (Feature #89951); Extbase has no equivalent.
 *
 * ## The guard is the reason this class exists
 *
 * Deleting a `sys_file` hard-deletes **every** `sys_file_reference` row that
 * points at it, in every table, unconditionally:
 *
 * ```php
 * // cms-core/Classes/Resource/Processing/FileDeletionAspect.php:79-85
 * // remove all references
 * $this->connectionPool->getConnectionForTable('sys_file_reference')->delete(
 *     'sys_file_reference',
 *     [
 *         'uid_local' => $fileObject->getUid(),
 *     ]
 * );
 * ```
 *
 * That runs from an `#[AsEventListener]` on `AfterFileDeletedEvent`
 * (`FileDeletionAspect.php:62-66`), which `ResourceStorage::deleteFile()`
 * dispatches at `:1817-1819`. A caller cannot opt out of it. The failure mode
 * of an unguarded delete is therefore not "an orphaned file wastes disk" — it
 * is *the image silently disappearing from records this extension does not
 * own*. Which is also why the built-in `@delete` flow is not used: it deletes
 * the file unconditionally (`FileHandlingService.php:344-345`), and the 13.3
 * feature changelog says so in as many words.
 *
 * ## The rule is asymmetric on purpose
 *
 * Two independent sources are consulted and **either** of them keeps the file:
 *
 * - `sys_file_reference`, which is the relation the FAL delete listener above
 *   destroys. Counted with a `DeletedRestriction` only — `hidden` is an
 *   enable column on that table, and a hidden reference is still a reference.
 * - `sys_refindex` on `ref_table = 'sys_file'`, which additionally records
 *   usages that no `sys_file_reference` row covers at all, such as a
 *   `t3://file` link in RTE content.
 *
 * An orphan costs disk. A wrongly deleted file costs somebody else's record.
 * When the two disagree — a stale reference index, most likely — the file
 * stays.
 *
 * ## Ordering is the caller's responsibility, and it is load-bearing
 *
 * This service asks the database what still points at the file, so it may only
 * be called once the database no longer holds the state the write replaced:
 * **after `persistAll()`**, and after the caller's own `sys_file_reference` row
 * has been repointed or deleted. Before that, the row still carries the old
 * `uid_local`, and the count would be the caller's own reference. That
 * ordering lives in {@see ProfilePersistenceService}, which is the only caller.
 *
 * `$ignoredFileReferenceUid` makes the check independent of *which* of the two
 * happened. The caller names its own reference row, this service excludes it
 * from both sources, and the threshold is then zero in every path — including
 * the one where the row was soft deleted through
 * `\TYPO3\CMS\Core\Resource\FileReference::delete()`, which writes the
 * `deleted` flag directly and updates no reference index entry.
 *
 * ## "The physical file is deleted" is storage dependent
 *
 * `ResourceStorage::deleteFile()` moves the file into the nearest `_recycler_`
 * folder when the storage has one, rather than unlinking it
 * (`cms-core/Classes/Resource/ResourceStorage.php:1793-1804`). The `sys_file`
 * record is gone either way; disk usage does not necessarily drop.
 *
 * The service is stateless: it holds two collaborators and nothing derived from
 * a request.
 */
final readonly class UnreferencedFileCleanupService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ResourceFactory $resourceFactory,
    ) {}

    /**
     * Deletes the file when nothing except the named reference points at it.
     *
     * Answers `false` for every reason not to delete, and that includes the
     * reasons that are not refusals — an already vanished `sys_file` row, a
     * storage that denies the deletion. None of them is an error for the caller:
     * the write it performed has already succeeded and been persisted, and a
     * file that survives is the safe outcome of this method, not a failed one.
     *
     * @param int $fileUid the `sys_file` uid that was replaced or cleared
     * @param int|null $ignoredFileReferenceUid the caller's own `sys_file_reference` uid, excluded from both counts
     * @return bool whether the `sys_file` record was deleted
     */
    public function deleteWhenUnreferenced(int $fileUid, ?int $ignoredFileReferenceUid = null): bool
    {
        if ($fileUid <= 0) {
            return false;
        }
        if ($this->countFileReferences($fileUid, $ignoredFileReferenceUid) > 0) {
            return false;
        }
        if ($this->countReferenceIndexEntries($fileUid, $ignoredFileReferenceUid) > 0) {
            return false;
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            // The record is already gone — deleted by a backend editor, by a
            // concurrent request, or by an earlier run of this method. Nothing
            // to do, and nothing that makes the caller's write wrong.
            return false;
        }

        try {
            return $file->delete();
        } catch (
            IllegalFileExtensionException
            | InsufficientFileWritePermissionsException
            | InsufficientFolderWritePermissionsException
        ) {
            // The three refusals `ResourceStorage::assureFileDeletePermissions()`
            // raises (`:938-962`): a denied file extension, a storage whose
            // permissions forbid the deletion, and one that forbids writing to
            // folders. Keeping the file is the correct answer to all three;
            // failing the request that already saved the new image is not.
            return false;
        }
    }

    /**
     * The number of live `sys_file_reference` rows pointing at the file.
     *
     * `DeletedRestriction` and nothing else. The table is soft delete capable
     * (`cms-core/Configuration/TCA/sys_file_reference.php:11`), so a row that
     * was removed must not keep a file alive — but it also carries a `hidden`
     * enable column, and a hidden reference is a reference: the record it
     * belongs to still owns the file and an editor can publish it again at any
     * time. The restrictions are therefore set explicitly rather than left to
     * the default container, whose composition depends on the context the
     * query is built in.
     *
     * Workspace rows are counted as well, for the same conservative reason.
     * This extension refuses every write while a workspace is active
     * ({@see WorkspaceGuard}), so a workspace overlay of a reference is by
     * definition somebody else's.
     */
    private function countFileReferences(int $fileUid, ?int $ignoredFileReferenceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $queryBuilder
            ->count('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid_local',
                    $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)
                )
            );

        if ($ignoredFileReferenceUid !== null && $ignoredFileReferenceUid > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->neq(
                    'uid',
                    $queryBuilder->createNamedParameter($ignoredFileReferenceUid, Connection::PARAM_INT)
                )
            );
        }

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * The number of reference index entries pointing at the file.
     *
     * `sys_refindex` records every relation core knows about, including the
     * ones that are not `sys_file_reference` rows — a `t3://file` link in RTE
     * content is the common one, and it is invisible to the count above.
     *
     * The index is not stale because of this extension: Extbase updates it for
     * every row it writes (`cms-extbase/Classes/Persistence/Generic/Backend.php:603`,
     * `:744`, `:811`). It can be stale for other reasons, and this is the
     * direction in which that is harmless — a leftover entry keeps a file that
     * could have been deleted, which costs disk and nothing else.
     *
     * The caller's own reference is excluded by `(tablename, recuid)`, because
     * the entry naming it survives a soft delete of the row: FAL's
     * `FileReference::delete()` writes the `deleted` flag directly through DBAL
     * (`cms-core/Classes/Resource/FileReference.php:364-388`) and updates no
     * index.
     *
     * The exclusion is spelled as a disjunction of two inequalities rather than
     * as a negated conjunction, because TYPO3's `ExpressionBuilder` has no
     * `not()` — `NOT (a AND b)` is `(NOT a) OR (NOT b)`.
     *
     * `sys_refindex` has no `deleted` column, so no restriction applies here —
     * the table is a derived index rather than a record table.
     */
    private function countReferenceIndexEntries(int $fileUid, ?int $ignoredFileReferenceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->count('hash')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq(
                    'ref_table',
                    $queryBuilder->createNamedParameter('sys_file')
                ),
                $queryBuilder->expr()->eq(
                    'ref_uid',
                    $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)
                )
            );

        if ($ignoredFileReferenceUid !== null && $ignoredFileReferenceUid > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->neq(
                        'tablename',
                        $queryBuilder->createNamedParameter('sys_file_reference')
                    ),
                    $queryBuilder->expr()->neq(
                        'recuid',
                        $queryBuilder->createNamedParameter($ignoredFileReferenceUid, Connection::PARAM_INT)
                    )
                )
            );
        }

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }
}
