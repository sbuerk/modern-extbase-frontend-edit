<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\ProfileEditRepository;
use SBUERK\ModernExtbaseFrontendEdit\Security\FrontendUserProfileOwnershipResolver;
use SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;

/**
 * Ownership resolution, the layer every authorization decision of the edit
 * flow rests on.
 *
 * The two cases that matter are the ones that look like data errors and are
 * not: `UserAspect::get('id')` returns `0` for a visitor without a session, and
 * a profile whose `fe_user` column is `0` is a perfectly ordinary record — an
 * editor created it, an import wrote it, a bug cleared it. Comparing the two
 * without denying first makes every visitor the owner of every unassigned
 * profile, which is a disclosure, not a glitch.
 *
 * The guard exists twice on purpose, in the service and in the SQL of
 * `findAllByFrontendUser()`, and the tests below call through the interface, so
 * they hold for a replacement implementation as well.
 *
 * ## Why the resolver is constructed rather than fetched
 *
 * `$this->get(ProfileOwnershipResolverInterface::class)` cannot resolve it
 * today. The dependency injection defaults of this extension keep services
 * private, `#[AsAlias]` on
 * {@see FrontendUserProfileOwnershipResolver} does not declare `public: true`,
 * and no consumer injects the interface yet — so the container compiler drops
 * both the alias and the service as unused. What these tests therefore cover is
 * the *behaviour* of the resolver, with its one collaborator taken from the
 * container; that the wiring resolves for a consumer is not covered here and
 * cannot be, until either the alias is published or a consumer exists.
 */
final class ProfileOwnershipResolverTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/Profiles.csv');
    }

    /**
     * The owned set contains the hidden profile: a profile its owner disabled
     * is still theirs, and the edit plugin has to show and un-hide it. That is
     * why ownership resolves against the edit repository, and it is only safe
     * because the query is owner constrained.
     */
    #[Test]
    public function resolverReturnsTheProfilesOfTheGivenFrontendUserIncludingHiddenOnes(): void
    {
        $owned = [];
        $this->executeInFrontendContext(function () use (&$owned): void {
            $owned = $this->ownedUids(1);
        });

        // 2 is hidden and owned, 3/4/8 are owned but scheduled, expired and in
        // a workspace, 5 belongs to frontend user 2 and 6 to nobody.
        $this->assertSame([1, 2, 7], $owned);
    }

    #[Test]
    public function profileOfAnotherFrontendUserIsNeverInTheOwnedSet(): void
    {
        $ownedByOne = [];
        $ownedByTwo = [];
        $foreignIsOwnedByOne = true;
        $this->executeInFrontendContext(function () use (&$ownedByOne, &$ownedByTwo, &$foreignIsOwnedByOne): void {
            $ownedByOne = $this->ownedUids(1);
            $ownedByTwo = $this->ownedUids(2);

            $foreign = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(5);
            $this->assertInstanceOf(Profile::class, $foreign);
            $foreignIsOwnedByOne = $this->resolver()->isOwnedProfile(1, $foreign);
        });

        $this->assertNotContains(5, $ownedByOne);
        $this->assertSame([5], $ownedByTwo);
        $this->assertFalse($foreignIsOwnedByOne);
    }

    #[Test]
    public function anonymousFrontendUserOwnsNothing(): void
    {
        $owned = ['not executed'];
        $unownedIsOwnedByAnonymous = true;
        $this->executeInFrontendContext(function () use (&$owned, &$unownedIsOwnedByAnonymous): void {
            $owned = $this->ownedUids(0);

            // Profile 6 carries fe_user = 0, which is exactly the value an
            // anonymous caller is identified by.
            $unowned = $this->get(ProfileEditRepository::class)->findByUidIncludingHidden(6);
            $this->assertInstanceOf(Profile::class, $unowned);
            $unownedIsOwnedByAnonymous = $this->resolver()->isOwnedProfile(0, $unowned);
        });

        $this->assertSame([], $owned);
        $this->assertFalse($unownedIsOwnedByAnonymous);
    }

    #[Test]
    public function profileWithoutAnOwnerBelongsToNobody(): void
    {
        $ownedByOne = [];
        $ownedByTwo = [];
        $this->executeInFrontendContext(function () use (&$ownedByOne, &$ownedByTwo): void {
            $ownedByOne = $this->ownedUids(1);
            $ownedByTwo = $this->ownedUids(2);
        });

        $this->assertNotContains(6, $ownedByOne);
        $this->assertNotContains(6, $ownedByTwo);
    }

    /**
     * A profile that has not been persisted has no identity to compare
     * against, so answering "owned" for it would let a check pass for an object
     * the caller just constructed.
     */
    #[Test]
    public function unpersistedProfileIsNeverOwned(): void
    {
        $isOwned = true;
        $this->executeInFrontendContext(function () use (&$isOwned): void {
            $isOwned = $this->resolver()->isOwnedProfile(1, new Profile());
        });

        $this->assertFalse($isOwned);
    }

    /**
     * @return list<int>
     */
    private function ownedUids(int $frontendUserId): array
    {
        $uids = [];
        foreach ($this->resolver()->resolveOwnedProfiles($frontendUserId) as $profile) {
            $uids[] = (int)$profile->getUid();
        }
        sort($uids);

        return $uids;
    }

    /**
     * The resolver under test, with the repository taken from the container —
     * see the class docblock for why it is not fetched as a service.
     */
    private function resolver(): ProfileOwnershipResolverInterface
    {
        return new FrontendUserProfileOwnershipResolver($this->get(ProfileEditRepository::class));
    }
}
