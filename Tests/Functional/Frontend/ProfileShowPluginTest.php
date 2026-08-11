<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `show` plugin, rendered through a real frontend request.
 *
 * Besides the rendering itself, two things only exist end to end and are
 * asserted here:
 *
 * - the **order** of the aggregate children, which is the manual sorting order
 *   and comes out of the `ObjectStorage` the data mapper filled, and
 * - the **404 cases**, which are produced by
 *   `ActionController::handleArgumentMappingExceptions()` and depend on the two
 *   `mvc` switches of the plugin TypoScript being in place.
 */
final class ProfileShowPluginTest extends AbstractProfilePluginTestCase
{
    /**
     * The uid of the profile every rendering test below uses: the only fixture
     * profile carrying a birthday, a biography, addresses and e-mail addresses.
     */
    private const ADA_PROFILE_UID = 1;

    #[Test]
    public function showRendersTheRequestedProfile(): void
    {
        $response = $this->renderShowPlugin(self::ADA_PROFILE_UID);

        $this->assertSame(200, $response->getStatusCode());

        $cards = $this->profileCards((string)$response->getBody());
        $this->assertSame(['Ada Lovelace'], array_keys($cards));
    }

    /**
     * Every label the detail view renders, by its text.
     *
     * The section headings and the two type label lookups are the labels that
     * render as an empty string when a `trans-unit` is missing — the exact
     * failure this test exists to make impossible.
     *
     * @return \Generator<string, array{label: string}>
     */
    public static function labelsOfTheDetailView(): \Generator
    {
        yield 'profile.birthday' => ['label' => 'Birthday'];
        yield 'profile.bio' => ['label' => 'Biography'];
        yield 'profile.addresses' => ['label' => 'Addresses'];
        yield 'profile.emails' => ['label' => 'E-mail addresses'];
        yield 'address type "work"' => ['label' => 'Work'];
        yield 'address type "others"' => ['label' => 'Others'];
        yield 'address type "home"' => ['label' => 'Home'];
        yield 'e-mail type "business"' => ['label' => 'Business'];
        yield 'e-mail type "private"' => ['label' => 'Private'];
    }

    #[DataProvider('labelsOfTheDetailView')]
    #[Test]
    public function showResolvesEveryLabelItRenders(string $label): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertStringContainsString($label, $body);
    }

    /**
     * The birthday, formatted with the installation wide date format the base
     * test case pins — see its `$configurationToUseInTestInstance`.
     */
    #[Test]
    public function showRendersTheBirthdayAndTheBiography(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertStringContainsString('<dd>1980-05-17</dd>', $body);
        $this->assertStringContainsString('Wrote the first algorithm.', $body);
    }

    /**
     * The addresses in the manual sorting order of the fixture, which is
     * deliberately not the uid order: uids 1, 2, 3 carry the sorting values
     * 3, 1, 2.
     *
     * The hidden address is asserted absent in the same test, because "in this
     * order" and "and nothing else" is one statement about the rendered list.
     */
    #[Test]
    public function showRendersTheVisibleAddressesInTheirManualSortingOrder(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertSame(
            ['Difference Engine Road 1', 'Bernoulli Street 2', 'Analytical Engine Lane 3'],
            $this->renderedInOrder(
                // Only up to the first tag: the second address line follows a
                // "<br />" inside the same element and is asserted below.
                '#<span class="modern-extbase-frontend-edit-profile-address">([^<]+)#s',
                $body,
            ),
        );
        $this->assertStringNotContainsString('Hidden Alley 4', $body);
    }

    /**
     * The optional second address line, which the partial renders behind a
     * `<br />` and only when it is filled — the fixture fills it for exactly
     * one of the three visible addresses.
     */
    #[Test]
    public function showRendersTheSecondAddressLineOnlyWhereThereIsOne(): void
    {
        $renderedAddresses = $this->renderedInOrder(
            '#<span class="modern-extbase-frontend-edit-profile-address">(.*?)</span>#s',
            (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody(),
        );
        $this->assertCount(3, $renderedAddresses);

        $withSecondLine = array_filter(
            $renderedAddresses,
            static fn(string $renderedAddress): bool => str_contains($renderedAddress, '<br />'),
        );
        $this->assertCount(1, $withSecondLine);
        $this->assertStringContainsString(
            '<br />Second line of the first address',
            implode('', $withSecondLine),
        );
    }

    /**
     * The type labels of the addresses, in the same order — which is what
     * proves the label lookup follows the record and is not a coincidence of
     * one row.
     */
    #[Test]
    public function showRendersTheAddressAndEmailTypeLabelsOfEachChild(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertSame(
            ['Work', 'Others', 'Home', 'Business', 'Private'],
            $this->renderedInOrder(
                '#<span class="modern-extbase-frontend-edit-profile-type">(.*?)</span>#s',
                $body,
            ),
        );
    }

    /**
     * The e-mail addresses in the manual sorting order of the fixture, which is
     * the reverse of the uid order.
     */
    #[Test]
    public function showRendersTheEmailAddressesInTheirManualSortingOrder(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertSame(
            ['first@example.org', 'second@example.org'],
            $this->renderedInOrder('#<a href="mailto:([^"]+)"#s', $body),
        );
    }

    #[Test]
    public function showRendersNoEditLinkForAnAnonymousVisitor(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertStringNotContainsString('Edit profile', $body);
        $this->assertStringNotContainsString('/edit-profile', $body);
    }

    #[Test]
    public function showRendersTheEditLinkForTheOwnerOfTheProfile(): void
    {
        $body = (string)$this->renderShowPlugin(
            self::ADA_PROFILE_UID,
            self::OWNER_FRONTEND_USER_ID,
        )->getBody();

        $this->assertStringContainsString('href="/edit-profile"', $body);
        $this->assertStringContainsString('Edit profile', $body);
    }

    #[Test]
    public function showRendersNoEditLinkForAFrontendUserWhoDoesNotOwnTheProfile(): void
    {
        $body = (string)$this->renderShowPlugin(
            self::ADA_PROFILE_UID,
            self::OTHER_FRONTEND_USER_ID,
        )->getBody();

        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringNotContainsString('Edit profile', $body);
        $this->assertStringNotContainsString('/edit-profile', $body);
    }

    /**
     * The three uids that must not resolve, and the reason each one does not.
     *
     * The last one is the interesting case: the property mapper resolves a uid
     * through `Backend::getObjectByIdentifier()`, which calls
     * `setRespectStoragePage(false)`, so the record *is* found there. Only the
     * second lookup through the display repository rejects it — and it has to
     * be indistinguishable from a uid that does not exist at all.
     *
     * @return \Generator<string, array{profileUid: int}>
     */
    public static function unreachableProfileUids(): \Generator
    {
        yield 'hidden profile' => ['profileUid' => 2];
        yield 'deleted profile' => ['profileUid' => 3];
        yield 'profile outside the configured storage page' => ['profileUid' => 5];
        yield 'profile uid that does not exist' => ['profileUid' => 99];
    }

    #[DataProvider('unreachableProfileUids')]
    #[Test]
    public function showReturnsPageNotFoundForAProfileTheListWouldNotShow(int $profileUid): void
    {
        $response = $this->renderShowPlugin($profileUid);

        $this->assertSame(404, $response->getStatusCode());
    }
}
