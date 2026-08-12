..  include:: /Includes.rst.txt

..  _known-limitations:

=================
Known limitations
=================

This extension is a proof of concept. Several of its decisions are deliberate
trade-offs that would be wrong in a production extension, and they are recorded
here instead of being hidden. Everything below is a property of the shipped
code, not a plan.

Languages and workspaces
========================

Editing is refused while a workspace is active
    The write path uses the Extbase persistence manager, which writes plain
    :code:`INSERT` and :code:`UPDATE` statements against the live row — it
    never creates a workspace version. A save issued from a workspace would
    therefore change the **published** record while the editor believes the
    opposite, so every write is refused with :code:`409` instead.

    The refusal is enforced twice: by the endpoints, and again by the
    persistence service at the boundary that performs the write. The edit
    plugin does not wait for it — in a workspace it renders the profile read
    only, loads neither the JavaScript nor the stylesheet of the editing
    surface, issues no request token, and says that editing is available in the
    live workspace only.

    Backend editing is unaffected and workspace aware as usual. There is no
    setting to change this.

Only default language records, and no translation is created
    Nothing in the write path assigns :sql:`sys_language_uid` or
    :sql:`l10n_parent`. A record created from the frontend therefore lands in
    the default language, and a translation can neither be created nor linked
    to its original from the frontend. Translations are made in the backend.

    The payload objects carry no language field either, so a request cannot
    choose one.

Writing records
===============

The write path does not go through :php:`DataHandler`
    Records are written with the Extbase persistence manager. No
    :php:`DataHandler` hook and none of the processing :php:`DataHandler`
    performs runs for a frontend save.

    In particular, no :sql:`sys_history` entry is written. TYPO3 v14.2 added an
    opt-in tracker that records Extbase persistence in :sql:`sys_history`,
    behind the feature toggle :php:`extbase.enableHistoryTracking`, which is
    disabled by default; TYPO3 v13 has no equivalent at all.

    ..  note::

        The reference index is **not** among the consequences. Extbase updates
        :sql:`sys_refindex` for every row it writes, so a frontend save leaves
        it consistent.

A failed write is not rolled back
    A save is not a database transaction, and the Extbase storage backend
    offers none. If it fails part way through, what was already written stays
    written and the profile can be left half updated. Every write method
    flushes exactly once to keep that window as small as the API allows, but
    the window exists. A save that reports an error should be repeated rather
    than assumed to have changed nothing.

A profile cannot be hidden or published from the frontend
    Whether a profile is hidden is part of every response, so an interface can
    show the state, but no endpoint changes it. The visibility endpoint
    addresses an address or an e-mail address only. Use the backend.

Existing gaps in the sorting are not repaired
    Reordering a collection renumbers it densely. A collection whose order is
    **not** changed is left exactly as it is, so gaps a record already had —
    from an earlier backend sorting operation, or from a record deleted
    elsewhere — remain. This is invisible in both the frontend and the backend,
    which read records in their stored order rather than by their sorting
    number.

Access group restrictions are not part of the model
    None of the three tables carries an :sql:`fe_group` column. Visibility is
    hiding, start time and end time — a profile cannot be restricted to a
    website user group.

Concurrency and abuse
=====================

Simultaneous edits overwrite each other
    There is no optimistic locking. Nothing in a request identifies the version
    of the record it was based on, no response carries one, and no endpoint
    compares one. Two sessions editing the same profile overwrite each other's
    changes, the last write wins, and neither is told. Where that matters,
    treat a profile as edited by one person at a time.

No rate limiting
    No endpoint declares a request limit. TYPO3 v14 offers an Extbase level
    mechanism for it and TYPO3 v13 does not, and using it on one version only
    would leave the two supported versions with different behaviour. Where this
    matters, limit the requests in front of TYPO3, in the web server or in a
    reverse proxy.

The profile image
=================

One image, no cropping, no variants
    A profile has exactly one image. It is stored and delivered as it was
    uploaded — there is no cropping step, no focus point and no generated size
    variants. The Fluid partial that renders it writes a plain :code:`<img>`
    tag and applies no image processing; an installation needing processed
    images replaces that one partial.

Image metadata cannot be edited from the frontend
    Title, alternative text and the remaining metadata live on the file record
    and on the file reference. They are part of the response document, so they
    can be displayed, and no endpoint writes them. Editing them is a backend
    task.

The image is not part of a full save
    Picking a file uploads it immediately; there is no apply step for it, and a
    save carrying the other fields neither changes nor clears the image.

A file another record uses is never deleted
    When an image is replaced or removed, the previous file is deleted only if
    neither a live :sql:`sys_file_reference` row nor a :sql:`sys_refindex`
    entry outside the profile's own reference still names it. This is the safe
    direction, and it means an installation can accumulate files whose last
    reference vanished in a way the reference index did not record. Whether a
    deleted file frees disk space additionally depends on the storage: one with
    a recycler folder receives the file instead of removing it.

Display and caching
===================

Every plugin of this extension renders uncached
    All three plugins depend on the logged-in website user, while the TYPO3
    page cache identifier varies by website user **groups** rather than by user
    uid — two members of one group would otherwise share a cache entry, and for
    the edit plugin that entry would carry another user's profile and request
    token. All plugin actions are therefore registered non-cacheable, and the
    endpoint page type sets :typoscript:`config.no_cache = 1`. Expect the page
    performance of any other uncached plugin.

The birthday is edited in the technical date format
    The read templates format the birthday with the installation's own date
    format. The editing surface shows and edits it as :code:`YYYY-MM-DD`, which
    is what the browser's date control uses and what is stored. Reading the
    same date in two formats on two pages is a real inconsistency and is
    accepted here.

The detail plugin does not link back to the list
    No setting names the page the list plugin sits on, so no back link is
    rendered. Use the site navigation or a link in the page content.
