<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Domain\Repository;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Display repository for {@see Address} records.
 *
 * The `list` and `show` plugins normally reach addresses through
 * `Profile->getAddresses()`. Relations are loaded with freshly built default
 * query settings, so that path yields visible records only — which is exactly
 * what the display side wants, and exactly what breaks the edit side, see
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AddressEditRepository}.
 * This repository exists for the cases that address the child table directly
 * and applies the same visibility rules.
 *
 * It carries no `initializeObject()` on purpose: restating the Extbase defaults
 * through `setDefaultQuerySettings()` would freeze `persistence.storagePid` at
 * the first instantiation of this shared service — see
 * {@see ProfileRepository} for the full reasoning.
 *
 * @extends Repository<Address>
 */
final class AddressRepository extends Repository {}
