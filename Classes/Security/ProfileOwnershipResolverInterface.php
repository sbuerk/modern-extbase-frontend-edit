<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Security;

use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;

/**
 * Resolves which profiles a frontend user owns.
 *
 * This is the only layer that knows how ownership is *stored*. The rule service
 * on top of it, the controllers and their tests know this interface and nothing
 * else, which is what makes the storage shape replaceable.
 *
 * ## Why the interface speaks in owned sets
 *
 * The obvious shape — "who owns this record?", returning an owner uid — encodes
 * a 1:1 relation in the contract. This extension does store ownership as a
 * single `fe_user` column, so that shape would work here and nowhere else: the
 * migration target of this design resolves ownership through an n:m table, in
 * which one frontend user owns several profiles and one profile may have
 * several owners. `int $frontendUserId → the profiles they own` maps onto a
 * column and onto an MM table equally well, so the second implementation is a
 * second class and no change anywhere else.
 *
 * ## Why it takes a Profile and not a uid
 *
 * {@see isOwnedProfile()} takes an object because taking a uid would force
 * every caller to look the record up first — from a request parameter, before
 * any ownership is known. That lookup is the insecure direct object reference
 * this design exists to remove: the server resolves the owned set from the
 * session, and a client supplied uid may only *filter* that set, never seed a
 * query. No implementation of this interface may call `findByUid()` on a
 * request derived uid.
 *
 * ## Anonymous callers
 *
 * `UserAspect::get('id')` returns `0` for a visitor without a session, and a
 * record whose owner column is `0` is a real possibility — written by an
 * editor, an import or a bug. An implementation must therefore deny before it
 * compares: user id `0` owns nothing, whatever the data says.
 */
interface ProfileOwnershipResolverInterface
{
    /**
     * All profiles owned by the given frontend user, in no guaranteed order.
     *
     * The argument comes from the session — `Context`, aspect `frontend.user` —
     * and never from the request. An id of `0` or below denotes an anonymous
     * caller and yields an empty list.
     *
     * @return list<Profile>
     */
    public function resolveOwnedProfiles(int $frontendUserId): array;

    /**
     * Whether the given profile is among the profiles the given frontend user
     * owns.
     *
     * A profile that has not been persisted yet is never owned: it has no
     * identity to compare against, and answering `true` for it would make the
     * check pass for an object the caller just constructed.
     */
    public function isOwnedProfile(int $frontendUserId, Profile $profile): bool;
}
