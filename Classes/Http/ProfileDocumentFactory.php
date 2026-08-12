<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Http;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;

/**
 * The profile document: the one shape the lit component reads a profile from.
 *
 * It is the `data` object of every successful endpoint response and, letter for
 * letter, the `data-profile` attribute the edit plugin renders into the page.
 * The component replaces its state wholesale with the document of the next
 * successful response, so two producers of this shape do not merely duplicate
 * code — they produce a surface that changes on the first save, and a field
 * that visibly reverts after a write the server accepted. That failure is
 * expensive to debug and impossible to see in a review of either side alone,
 * which is why the shape has exactly one producer.
 *
 * ## Why this sits next to `JsonEnvelope`
 *
 * The two together *are* the wire contract of this extension:
 * {@see JsonEnvelope} owns the outer object — `data` on success, `errors` on
 * failure, and the encoding — and this class owns what goes inside `data`. They
 * are also the only two classes both endpoint controllers and the edit plugin
 * share. Putting the document builder anywhere else would split one contract
 * across two namespaces, and `Domain\` was rejected for the opposite reason:
 * this is a transport representation, it names the JSON keys a TypeScript file
 * parses, and the domain must not know them.
 *
 * ## The children are arguments, and that is the point
 *
 * {@see create()} takes the addresses and the e-mail addresses rather than
 * reading them off the profile or fetching them itself, because the two callers
 * must not read them the same way — and the caller is the only place that can
 * know which is right:
 *
 * - Both current callers serve the **owner** and pass the collections of the
 *   `Edit\` repositories, which include the records the owner has hidden.
 *   Relations are reconstituted with query settings built from scratch
 *   (`DataMapper::getPreparedQuery()`), so `$profile->getAddresses()` never
 *   contains them — and they are exactly what the editing surface exists to
 *   show and to publish again.
 * - A caller serving a **visitor** — a read plugin, a future public endpoint —
 *   must not disclose them and would pass the display collections instead.
 *
 * A factory that decided this itself would have to guess the audience from
 * something it cannot see: the session, the plugin it was called from, a flag a
 * caller passes and can pass wrongly. The visibility policy is an authorization
 * decision, it belongs where authorization is decided, and a signature that
 * forces the caller to state it is the cheapest possible way to keep it there.
 *
 * The service is stateless and holds nothing.
 *
 * @see \SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileAjaxController
 * @see \SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileEditController
 */
final readonly class ProfileDocumentFactory
{
    /**
     * The document for one profile and the child records the caller resolved.
     *
     * The order of the two collections is preserved as given — it is the stored
     * sorting order, which the surface renders and the reordering endpoint
     * writes, and re-sorting here would silently disagree with both.
     *
     * @param list<Address> $addresses
     * @param list<Email> $emails
     * @return array<string, mixed>
     */
    public function create(Profile $profile, array $addresses, array $emails): array
    {
        $addressDocuments = [];
        foreach ($addresses as $address) {
            $addressDocuments[] = [
                'uid' => $address->getUid(),
                'type' => $address->getType(),
                'line1' => $address->getLine1(),
                'line2' => $address->getLine2(),
                'hidden' => $address->isHidden(),
            ];
        }

        $emailDocuments = [];
        foreach ($emails as $email) {
            $emailDocuments[] = [
                'uid' => $email->getUid(),
                'type' => $email->getType(),
                'email' => $email->getEmail(),
                'hidden' => $email->isHidden(),
            ];
        }

        return [
            'uid' => $profile->getUid(),
            'shortname' => $profile->getShortname(),
            'firstname' => $profile->getFirstname(),
            'lastname' => $profile->getLastname(),
            // The wire format is the DTO's constant, so what is read here is
            // spelled exactly like what may be written; '' is "no birthday",
            // matching the DTO default.
            'birthday' => $profile->getBirthday()?->format(ProfileData::BIRTHDAY_FORMAT) ?? '',
            'bio' => $profile->getBio(),
            // Readable so the surface can show the state, writable only through
            // the dedicated action and only for children.
            'hidden' => $profile->isHidden(),
            'addresses' => $addressDocuments,
            'emails' => $emailDocuments,
        ];
    }
}
