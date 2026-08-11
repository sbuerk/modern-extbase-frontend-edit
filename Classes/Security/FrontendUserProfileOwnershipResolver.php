<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Security;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Ownership resolver for the `fe_user` column shipped with this extension.
 *
 * Registered as the default implementation of
 * {@see ProfileOwnershipResolverInterface} with `#[AsAlias]`, so consumers
 * depend on the interface and an installation storing ownership differently —
 * an n:m table, for instance — replaces this one class.
 *
 * The service is stateless: it holds a repository and nothing derived from a
 * request. The frontend user id is an argument on every call rather than a
 * property, because this is a shared service and a cached identity would be
 * served to the next caller in the same request.
 *
 * ## Why the edit repository
 *
 * A profile the owner has hidden is still theirs, and the edit plugin has to
 * show and un-hide it, so ownership is resolved against
 * {@see ProfileEditRepository} rather than the display repository. The one
 * method called here, `findAllByFrontendUser()`, sees disabled records, which
 * is only safe because it is owner constrained: nobody else's disabled profile
 * can appear in a result set filtered by `fe_user`.
 */
#[AsAlias(ProfileOwnershipResolverInterface::class)]
final readonly class FrontendUserProfileOwnershipResolver implements ProfileOwnershipResolverInterface
{
    public function __construct(
        private ProfileEditRepository $profileEditRepository,
    ) {}

    /**
     * @return list<Profile>
     */
    public function resolveOwnedProfiles(int $frontendUserId): array
    {
        // Deny before comparing. An anonymous caller reads as id 0, and rows
        // whose owner column is 0 do occur, so without this the two would match
        // and every visitor would own every unassigned profile. The repository
        // query carries the same guard; this one keeps the rule visible in the
        // layer that states it.
        if ($frontendUserId <= 0) {
            return [];
        }

        return $this->profileEditRepository->findAllByFrontendUser($frontendUserId)->toArray();
    }

    public function isOwnedProfile(int $frontendUserId, Profile $profile): bool
    {
        $profileUid = $profile->getUid();
        if ($profileUid === null || $profileUid <= 0) {
            return false;
        }

        foreach ($this->resolveOwnedProfiles($frontendUserId) as $ownedProfile) {
            if ($ownedProfile->getUid() === $profileUid) {
                return true;
            }
        }

        return false;
    }
}
