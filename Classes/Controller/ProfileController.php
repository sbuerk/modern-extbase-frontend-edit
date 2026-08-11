<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Controller;

use Psr\Http\Message\ResponseInterface;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Property\Exception\TargetNotFoundException;

/**
 * The read side of the feature: the `list` and the `show` plugin.
 *
 * Both actions read through the **display** repository
 * {@see ProfileRepository}, never through the `Edit\` counterparts. The display
 * repository sees visible records only and honours `persistence.storagePid`,
 * which is what a public listing is supposed to show. The edit repositories
 * exist to see hidden records and have no business in a public plugin.
 *
 * ## The ownership flag is a display decision, not a security boundary
 *
 * Both actions hand the templates an `editable` flag, and the templates render
 * an edit link when it is `true`. **That flag protects nothing.** It decides
 * whether a link is drawn, and a link that is not drawn is still reachable by
 * typing the URL. The authorization boundary lives on the write endpoints,
 * which resolve the frontend user from the session and navigate the owned
 * aggregate — see `docs/frontend-edit/authorization.md`. Do not "reuse" this
 * flag as a guard, and do not remove a guard elsewhere because this flag
 * exists.
 *
 * ## Why the controller computes it
 *
 * Templates receive plain data, never a service: the ownership resolver is not
 * assigned to the view and no ViewHelper asks it anything. The controller
 * resolves the owned set once and reduces it to a boolean per record, so a
 * template can neither ask the wrong question nor ask it once per row.
 *
 * ## Why both plugins are registered non-cacheable
 *
 * The `editable` flag depends on the logged-in frontend user, and the TYPO3
 * page cache identifier varies by **group ids, not by user uid**
 * (`cms-frontend/Classes/Middleware/PrepareTypoScriptFrontendRendering.php`).
 * Two users in the same frontend user group therefore share one cache entry,
 * so a cached rendering carrying user A's edit links would be served to every
 * other member of A's groups. `ext_localconf.php` lists both actions as
 * non-cacheable for that reason; the rendering becomes `USER_INT` and the
 * question does not arise.
 *
 * The class is `final` but not `readonly`: `ActionController` is not `readonly`
 * and a `readonly` class cannot extend a non-`readonly` one. It is not
 * registered explicitly either — `Configuration/Services.php` loads `Classes/`
 * with `autoconfigure`, and core tags every `ControllerInterface` and makes it
 * public and non-shared in `EXT:extbase/Configuration/Services.php`.
 */
final class ProfileController extends ActionController
{
    public function __construct(
        private readonly ProfileRepository $profileRepository,
        private readonly ProfileOwnershipResolverInterface $profileOwnershipResolver,
        private readonly Context $context,
    ) {}

    /**
     * Lists the visible profiles, each with a display-only ownership flag.
     *
     * Assigns one variable, `profiles`, holding a list of entries shaped
     * `['profile' => Profile, 'editable' => bool]`. An entry rather than two
     * parallel variables, because a template must not have to correlate a
     * profile with a membership test — that would be logic in the template.
     */
    public function listAction(): ResponseInterface
    {
        $ownedProfileUids = $this->resolveOwnedProfileUids();

        $profiles = [];
        foreach ($this->profileRepository->findAll() as $profile) {
            $profileUid = $profile->getUid();
            $profiles[] = [
                'profile' => $profile,
                // Display only. See the class docblock: this decides whether an
                // edit link is rendered, it does not authorise anything.
                'editable' => $profileUid !== null && isset($ownedProfileUids[$profileUid]),
            ];
        }

        $this->view->assign('profiles', $profiles);

        return $this->htmlResponse();
    }

    /**
     * Renders one profile.
     *
     * The argument is **required and not nullable**, which is what makes the
     * failure cases well defined rather than accidental:
     *
     * - A missing `profile` argument raises `RequiredArgumentMissingException`
     *   in `ActionController::mapRequestArgumentsToControllerArguments()`.
     * - A uid that resolves to nothing raises `TargetNotFoundException` from
     *   `PersistentObjectConverter::fetchObjectFromPersistence()`.
     *
     * Both are routed into `handleArgumentMappingExceptions()`, which turns
     * them into the site's configured 404 response because the plugin
     * TypoScript sets `mvc.showPageNotFoundIfTargetNotFoundException = 1` and
     * `mvc.showPageNotFoundIfRequiredArgumentIsMissingException = 1` (Feature
     * #104321, TYPO3 v13.3). Without those two settings the exceptions
     * propagate and produce an exception page — the "confusing error" this
     * action deliberately does not produce. They are set in the site set and in
     * the classic TypoScript alike, so no installation gets one and not the
     * other.
     *
     * A record that is **not visible** — hidden, not yet started, expired,
     * access protected — is a `TargetNotFoundException` as well: the property
     * mapper looks the uid up through `Backend::getObjectByIdentifier()`, which
     * builds a fresh query without touching `ignoreEnableFields`, and that
     * stays `false` in the frontend.
     *
     * The one thing that lookup does **not** apply is `persistence.storagePid`:
     * it calls `setRespectStoragePage(false)` explicitly
     * (`Backend.php:186`). Without the guard below, a profile stored outside
     * the configured storage folder would be reachable through a hand-written
     * URL although the list plugin never links to it. So the resolved record is
     * asked for a second time, through the display repository and therefore
     * through the same query settings the list uses, and a record the list
     * would not have shown produces the same 404 as a uid that does not exist.
     *
     * That the two are indistinguishable is the point, not an accident — see
     * `docs/frontend-edit/authorization.md`, which requires "not in my set" and
     * "does not exist" to return the same status and the same body.
     */
    public function showAction(Profile $profile): ResponseInterface
    {
        if (!$this->isProfileWithinDisplayScope($profile)) {
            // This call always throws: either PropagateResponseException
            // carrying the 404 response, or the exception passed in, depending
            // on the mvc.showPageNotFoundIfTargetNotFoundException setting.
            // Routing through the core handler rather than building a response
            // here is what keeps this case byte identical to an unknown uid.
            $this->handleArgumentMappingExceptions(
                new TargetNotFoundException(
                    sprintf(
                        'Object of type %s with identity "%s" not found.',
                        Profile::class,
                        (string)$profile->getUid(),
                    ),
                    1786486668
                )
            );
        }

        $this->view->assignMultiple([
            'profile' => $profile,
            // Display only, exactly as in listAction().
            'editable' => $this->profileOwnershipResolver->isOwnedProfile(
                $this->resolveFrontendUserId(),
                $profile,
            ),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Whether the profile is inside what the display repository would return.
     *
     * The property mapper has already proven that the record exists and passes
     * the enable fields; this asks the remaining question, which is whether it
     * is on a page the plugin is configured to read from. `findOneBy()` builds
     * its query through `Repository::createQuery()` and therefore through
     * `QueryFactory::create()`, which is where `persistence.storagePid`,
     * `respectStoragePage` and the language aspect are applied — the very
     * settings `Backend::getObjectByIdentifier()` sidesteps.
     *
     * It is deliberately the same public repository API the listing goes
     * through, rather than a query assembled here: a controller that builds its
     * own constraints is a controller that can drift away from the repository
     * it is supposed to agree with. `findOneBy()` exists unchanged on both
     * target core versions.
     */
    private function isProfileWithinDisplayScope(Profile $profile): bool
    {
        $profileUid = $profile->getUid();
        if ($profileUid === null) {
            return false;
        }

        return $this->profileRepository->findOneBy(['uid' => $profileUid]) !== null;
    }

    /**
     * The uids of the profiles the current frontend user owns, as a lookup map.
     *
     * The owned set is resolved **once** and turned into an `isset()` map.
     * Asking {@see ProfileOwnershipResolverInterface::isOwnedProfile()} per row
     * would be the more obvious spelling and would re-resolve the whole owned
     * set for every listed profile.
     *
     * @return array<int, true>
     */
    private function resolveOwnedProfileUids(): array
    {
        $ownedProfileUids = [];
        foreach ($this->profileOwnershipResolver->resolveOwnedProfiles($this->resolveFrontendUserId()) as $ownedProfile) {
            $ownedProfileUid = $ownedProfile->getUid();
            if ($ownedProfileUid !== null) {
                $ownedProfileUids[$ownedProfileUid] = true;
            }
        }

        return $ownedProfileUids;
    }

    /**
     * The uid of the logged-in frontend user, or `0`.
     *
     * The aspect is read per call and never cached in a property: `Context` is
     * a singleton and its `frontend.user` aspect is *replaced* — by
     * `FrontendUserAuthenticator` and again by `PreviewSimulator`.
     *
     * `getAspect()` is used rather than `getPropertyFromAspect()` because it
     * creates an empty `UserAspect` when none was set, which reads as an
     * anonymous caller. The `isLoggedIn()` check comes first because
     * `get('id')` yields `0` for an anonymous visitor, and `0` is a value the
     * owner column of a record can genuinely hold.
     */
    private function resolveFrontendUserId(): int
    {
        $userAspect = $this->context->getAspect('frontend.user');
        if (!$userAspect instanceof UserAspect || !$userAspect->isLoggedIn()) {
            return 0;
        }

        return (int)$userAspect->get('id');
    }
}
