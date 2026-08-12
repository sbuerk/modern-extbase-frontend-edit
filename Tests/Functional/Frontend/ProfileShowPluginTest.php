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
 *   `mvc` switches of the plugin TypoScript being in place. Both switches have
 *   a test of their own:
 *   {@see showReturnsPageNotFoundForAProfileTheListWouldNotShow()} covers
 *   `showPageNotFoundIfTargetNotFoundException`, and
 *   {@see showReturnsPageNotFoundWhenTheProfileArgumentIsMissing()} covers
 *   `showPageNotFoundIfRequiredArgumentIsMissingException`.
 *
 * The `Profile/Image` partial is covered from here as well. It needs a FAL
 * fixture and a real file in the test instance, which
 * {@see \SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\ProfileImageFixtureTrait}
 * provides for every plugin test.
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
     * The line breaks of the biography, which `f:format.nl2br` turns into
     * markup.
     *
     * A biography is a multi line text field, and without the ViewHelper the
     * newlines survive into the HTML source and collapse into a single space in
     * the browser. The fixture text is two lines for exactly this assertion —
     * with a single line body, the ViewHelper is a no-op and can be removed
     * without any test noticing.
     */
    #[Test]
    public function showRendersTheLineBreaksOfTheBiographyAsMarkup(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertStringContainsString('Wrote the first algorithm.<br />', $body);
        $this->assertStringContainsString('And the second one, too.', $body);
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
     * The type label of every child, paired with the child it belongs to —
     * which is what proves the label lookup follows the record and is not a
     * coincidence of one row.
     *
     * Paired rather than listed in render order on purpose. A list of labels
     * additionally fails whenever the sorting changes, which
     * {@see showRendersTheVisibleAddressesInTheirManualSortingOrder()} and
     * {@see showRendersTheEmailAddressesInTheirManualSortingOrder()} already
     * assert; this test would then report a labelling defect for a sorting one.
     */
    #[Test]
    public function showRendersTheAddressAndEmailTypeLabelsOfEachChild(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertSame(
            [
                'Analytical Engine Lane 3' => 'Home',
                'Bernoulli Street 2' => 'Others',
                'Difference Engine Road 1' => 'Work',
            ],
            $this->renderedPairs(
                '#<span class="modern-extbase-frontend-edit-profile-type">([^<]*)</span>\s*'
                    . '<span class="modern-extbase-frontend-edit-profile-address">([^<]+)#s',
                $body,
            ),
        );

        $this->assertSame(
            [
                'first@example.org' => 'Business',
                'second@example.org' => 'Private',
            ],
            $this->renderedPairs(
                '#<span class="modern-extbase-frontend-edit-profile-type">([^<]*)</span>\s*'
                    . '<a href="mailto:([^"]+)"#s',
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

    /**
     * The `Profile/Image` partial, rendered for the profile that carries an
     * image with a metadata record.
     *
     * The dimension attributes are asserted because they are written behind an
     * `f:if` each: they are `NULL` for a file without image metadata, and an
     * `f:if` that always renders would emit `width=""`, which is invalid HTML
     * and which the second half of this pair
     * ({@see \SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend\ProfileListPluginTest::listRendersNoDimensionAttributesForAnImageWithoutMetadata()})
     * is what makes falsifiable.
     */
    #[Test]
    public function showRendersTheProfileImage(): void
    {
        $body = (string)$this->renderShowPlugin(self::IMAGE_PROFILE_UID)->getBody();

        $this->assertStringContainsString(
            '<figure class="modern-extbase-frontend-edit-profile-image">',
            $body,
        );
        $this->assertStringContainsString('src="' . self::IMAGE_RENDERED_URL . '"', $body);
        $this->assertStringContainsString('width="' . self::IMAGE_WIDTH . '"', $body);
        $this->assertStringContainsString('height="' . self::IMAGE_HEIGHT . '"', $body);
        $this->assertStringContainsString(
            '<figcaption>' . self::IMAGE_REFERENCE_TITLE . '</figcaption>',
            $body,
        );
    }

    /**
     * The `profile.image.alt` label, asserted by its **text**.
     *
     * This is the assertion the partial exists for and the one that is easy to
     * get wrong: a missing `trans-unit` renders as an empty string, so an
     * `alt=""` is what a broken label produces — valid HTML, a passing test,
     * and an image with no alternative text. The fixture reference of this
     * profile deliberately carries no `alternative` of its own, which is what
     * makes the label the value that ends up in the attribute at all.
     */
    #[Test]
    public function showResolvesTheAlternativeTextLabelForAnImageWithoutOne(): void
    {
        $body = (string)$this->renderShowPlugin(self::IMAGE_PROFILE_UID)->getBody();

        $this->assertStringContainsString(
            'alt="Portrait of ' . self::IMAGE_PROFILE_NAME . '"',
            $body,
        );
    }

    /**
     * A profile without an image renders no image markup, and renders.
     *
     * `getProfileImage()` returns `null` there, and the partial is asked to
     * render regardless — the guard is inside it. The status code is asserted
     * together with the absence, because "no `<figure>` in the body" is also
     * true of an exception page.
     */
    #[Test]
    public function showRendersNoImageMarkupForAProfileWithoutAnImage(): void
    {
        $response = $this->renderShowPlugin(self::ADA_PROFILE_UID);

        $this->assertSame(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringNotContainsString(
            '<figure class="modern-extbase-frontend-edit-profile-image">',
            $body,
        );
        $this->assertStringNotContainsString('<img', $body);
    }

    #[Test]
    public function showRendersNoEditLinkForAnAnonymousVisitor(): void
    {
        $body = (string)$this->renderShowPlugin(self::ADA_PROFILE_UID)->getBody();

        $this->assertStringNotContainsString('Edit profile', $body);
        $this->assertStringNotContainsString('/edit-profile', $body);
    }

    /**
     * The profile without an owner, requested by a visitor without a session.
     *
     * The case both anonymous guards exist for: `UserAspect::get('id')` yields
     * `0`, {@see UNOWNED_PROFILE_UID} carries `fe_user = 0`, and a plain
     * comparison of the two makes every visitor the owner of every unassigned
     * profile. The test above cannot see that — "Ada Lovelace" has a non-zero
     * owner and is excluded by the comparison itself — so this is the rendering
     * that changes when the `$frontendUserId <= 0` early return of
     * `FrontendUserProfileOwnershipResolver::resolveOwnedProfiles()` and the
     * `feUser > 0` constraint of `ProfileEditRepository::findAllByFrontendUser()`
     * are both gone.
     */
    #[Test]
    public function showRendersNoEditLinkForAnAnonymousVisitorOnAProfileWithoutAnOwner(): void
    {
        $response = $this->renderShowPlugin(self::UNOWNED_PROFILE_UID);

        $this->assertSame(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $this->assertStringContainsString(self::UNOWNED_PROFILE_NAME, $body);
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

    /**
     * The second `mvc` switch:
     * `showPageNotFoundIfRequiredArgumentIsMissingException`.
     *
     * The request addresses the `show` action of the plugin and carries no
     * `profile` argument, which is the one thing no link this extension renders
     * can produce — it is a hand written or truncated URL. Extbase then raises
     * `RequiredArgumentMissingException` while mapping the arguments, and
     * without the switch that exception propagates into an exception page
     * instead of the site's 404.
     *
     * The cHash still has to be valid, and is calculated over the two arguments
     * that *are* present: without it `PageArgumentValidator` answers 404 before
     * Extbase is entered at all, and the test would assert the right status
     * code for the wrong reason. Removing the `mvc` switch is what tells the two
     * apart — the status then changes, which a cHash rejection would not do.
     */
    #[Test]
    public function showReturnsPageNotFoundWhenTheProfileArgumentIsMissing(): void
    {
        $response = $this->renderUri($this->showPluginUriForArguments([
            'action' => 'show',
            'controller' => 'Profile',
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }
}
