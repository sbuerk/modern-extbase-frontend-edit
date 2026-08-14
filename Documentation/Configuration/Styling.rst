..  include:: /Includes.rst.txt

..  _configuration-styling:

===================
Styling and theming
===================

The editing surface renders into the **light DOM**, so a site's own stylesheet
reaches it the same way it reaches any other markup on the page. There are two
ways to style it, and they compose:

*   **CSS custom properties.** Every colour, distance, radius, duration and
    width the surface uses is a property declared on the custom element. Setting
    one is the smallest possible change and needs no knowledge of the markup.
*   **Class names.** Every element carries a ``frontend-edit-*`` class that a
    site may write rules against, and an installation can have the surface carry
    *additional* classes of its own — a design system's ``button``,
    ``form-control`` and so on — so the surface inherits a theme rather than
    imitating it. See :ref:`configuration-component`.

..  note::
    Earlier versions drew the surface inside a shadow root, where a custom
    property was the *only* thing that crossed the boundary and no selector
    reached anything. That is no longer the case. The trade is deliberate and
    goes both ways: a site can now style the surface, and a site can now break
    it.

..  important::
    A site's declaration wins **whatever order the stylesheets load in**. The
    extension declares its defaults at zero specificity precisely so that a rule
    written in a site package does not have to compete with them — the
    extension's own stylesheet is emitted after the site's, and would otherwise
    always win.

Everything below is optional. The extension ships defaults that are deliberately
quiet — no typeface of its own, no brand colour, no decoration — so a site that
configures nothing gets a surface that stays out of the way.

Overriding a property
=====================

The properties are declared on the custom element itself. Set them on the same
element from the site's own stylesheet, and the site wins:

..  code-block:: css

    modern-extbase-frontend-edit-profile {
        --frontend-edit-color-accent: #b8003c;
        --frontend-edit-radius: 0;
        --frontend-edit-measure: 60rem;
    }

There is no specificity contest to win and no :css:`!important` to add. A
declaration made outside the component always beats the default the component
ships, and it reaches every field, button and message inside it.

..  note::

    Set the properties on :html:`modern-extbase-frontend-edit-profile` — the
    outer element — and not on the field or image elements inside it. Those
    inherit what the outer element carries, which is what makes one declaration
    reach the whole surface.

The properties
==============

Colour
------

..  list-table::
    :header-rows: 1

    *   -   Property
        -   Default
        -   Used for
    *   -   :css:`--frontend-edit-color-accent`
        -   :code:`#0a7bd4`
        -   The focus ring, the frame around the surface, and the fill of the
            emphasised button.
    *   -   :css:`--frontend-edit-color-accent-hover`
        -   :code:`#0968b4`
        -   The emphasised button under the pointer. A separate value because
            :css:`color-mix()` is not available at the browsers this extension
            supports.
    *   -   :css:`--frontend-edit-color-accent-contrast`
        -   :code:`#ffffff`
        -   Text drawn on the accent. Change it together with the accent, or the
            emphasised button loses its contrast.
    *   -   :css:`--frontend-edit-color-danger`
        -   :code:`#a4141a`
        -   Validation messages, the ring around a rejected control, and the
            label of a destructive button.
    *   -   :css:`--frontend-edit-color-danger-surface`
        -   :code:`#fdf2f2`
        -   The fill of a destructive button under the pointer.
    *   -   :css:`--frontend-edit-color-border`
        -   :code:`#c7ccd1`
        -   Decoration: the rule above a collection, the marker on a child, the
            frame around a stored image, the dialog and the state badge.
    *   -   :css:`--frontend-edit-color-border-control`
        -   :code:`#7d838a`
        -   The resting edge of a button, an input, a select and a textarea.
            See :ref:`the note below <configuration-styling-control-contrast>`
            before overriding it.
    *   -   :css:`--frontend-edit-color-border-strong`
        -   :code:`#656d75`
        -   The same control edges under the pointer, and the outline of the add
            form.
    *   -   :css:`--frontend-edit-color-surface`
        -   :code:`#ffffff`
        -   The background of buttons and controls.
    *   -   :css:`--frontend-edit-color-surface-sunken`
        -   :code:`#f2f4f5`
        -   The background of a hovered button, and behind an image.
    *   -   :css:`--frontend-edit-color-muted`
        -   :code:`#5c6469`
        -   Field labels, captions, the state badge, the empty value dash.

Spacing, shape and size
-----------------------

..  list-table::
    :header-rows: 1

    *   -   Property
        -   Default
        -   Used for
    *   -   :css:`--frontend-edit-measure`
        -   :code:`48rem`
        -   The width the surface is capped at. See :ref:`below <configuration-styling-measure>`.
    *   -   :css:`--frontend-edit-label-width`
        -   :code:`9rem`
        -   The label column of a field. Below roughly :code:`27rem` of
            available width the value wraps under its label instead.
    *   -   :css:`--frontend-edit-gap-within`
        -   :code:`0.25rem`
        -   Between a label and its value, where they are stacked.
    *   -   :css:`--frontend-edit-gap-field`
        -   :code:`0.5rem`
        -   Between two fields of a record.
    *   -   :css:`--frontend-edit-gap-record`
        -   :code:`1rem`
        -   Between two records of a collection.
    *   -   :css:`--frontend-edit-gap-section`
        -   :code:`1.5rem`
        -   Between the profile and a collection, and between collections.
    *   -   :css:`--frontend-edit-space-xs` … :css:`-xl`
        -   :code:`0.25rem` … :code:`1.5rem`
        -   The underlying five step scale the four gaps above are set from.
            Change a gap to move one distance, the scale to move everything.
    *   -   :css:`--frontend-edit-border-width`
        -   :code:`1px`
        -   Every border and every hairline rule.
    *   -   :css:`--frontend-edit-radius`
        -   :code:`0.25rem`
        -   Buttons, controls, the state badge.
    *   -   :css:`--frontend-edit-radius-lg`
        -   :code:`0.5rem`
        -   The profile image.
    *   -   :css:`--frontend-edit-control-min-height`
        -   :code:`2.25rem`
        -   The height of buttons and controls, and so the size of a touch target.
    *   -   :css:`--frontend-edit-control-padding-block`
        -   :code:`0.375rem`
        -   Padding above and below the text of a control.
    *   -   :css:`--frontend-edit-control-padding-inline`
        -   :code:`0.5rem`
        -   Padding left and right of it.

Type, focus, state and motion
-----------------------------

..  list-table::
    :header-rows: 1

    *   -   Property
        -   Default
        -   Used for
    *   -   :css:`--frontend-edit-font-family`
        -   :code:`inherit`
        -   The typeface. See :ref:`configuration-styling-typeface`.
    *   -   :css:`--frontend-edit-font-size-sm`
        -   :code:`0.875em`
        -   Labels, captions, validation messages, the state badge.
    *   -   :css:`--frontend-edit-label-weight`
        -   :code:`600`
        -   Field labels.
    *   -   :css:`--frontend-edit-focus-color`
        -   the accent
        -   The focus ring. Separate from the accent so its contrast can be
            raised on its own.
    *   -   :css:`--frontend-edit-focus-width`
        -   :code:`2px`
        -   Its thickness.
    *   -   :css:`--frontend-edit-focus-offset`
        -   :code:`2px`
        -   Its distance from the control.
    *   -   :css:`--frontend-edit-outline-color`
        -   the accent
        -   The dashed frame around the whole surface.
    *   -   :css:`--frontend-edit-outline-width`
        -   :code:`1px`
        -   Its thickness.
    *   -   :css:`--frontend-edit-busy-opacity`
        -   :code:`0.6`
        -   How far a field is dimmed while its request is in flight.
    *   -   :css:`--frontend-edit-transition-duration`
        -   :code:`120ms`
        -   Every transition, so one value governs all of them.
    *   -   :css:`--frontend-edit-transition-easing`
        -   :code:`ease`
        -   Their easing.

Buttons are not all the same weight
===================================

The surface marks two kinds of button, and leaves the rest plain:

..  list-table::
    :header-rows: 1

    *   -   Button
        -   Drawn as
        -   Which ones
    *   -   Commits a pending change
        -   Filled in the accent colour
        -   :guilabel:`Apply`, :guilabel:`Save all fields`, :guilabel:`Add`
    *   -   Destroys a record or a file
        -   Labelled in the danger colour, filled only under the pointer
        -   :guilabel:`Remove`
    *   -   Everything else
        -   The plain bordered button
        -   :guilabel:`Edit`, :guilabel:`Cancel`, :guilabel:`Move up`,
            :guilabel:`Move down`, :guilabel:`Hide`

Every button also carries an icon. They are drawn inline in the extension's own
JavaScript rather than loaded from anywhere, so they need no font, make no
request and are unaffected by the Content Security Policy. They take their colour
from the button they sit in and their size from the surrounding text, so a change
to :css:`--frontend-edit-color-danger` or to the page's font size moves them too.

In the toolbar of a child record — :guilabel:`Move up`, :guilabel:`Move down`,
:guilabel:`Hide`, :guilabel:`Remove` — the text is hidden and only the icon is
shown, because those four repeat once per address and e-mail address. The label
is still announced by a screen reader and still read by automated tests; it is
hidden visually, not removed.

There is no setting for this and no class to override. The distinction is
carried in a :html:`data-variant` attribute on the button, so a site that wants
a different treatment styles it directly — this is one of the few things a
stylesheet can reach, because the attribute selector applies inside the
component:

..  code-block:: css

    modern-extbase-frontend-edit-profile {
        /* Make the emphasised button match the site's own call to action. */
        --frontend-edit-color-accent: #00694e;
        --frontend-edit-color-accent-hover: #005840;
        --frontend-edit-color-accent-contrast: #ffffff;
    }

..  note::

    Changing :css:`--frontend-edit-color-accent` also changes the focus ring and
    the frame around the surface, because they are the same token. If the
    emphasised button needs a colour of its own, set
    :css:`--frontend-edit-focus-color` back to a value with enough contrast.

..  _configuration-styling-measure:

The width of the surface
========================

:css:`--frontend-edit-measure` caps the surface at :code:`48rem`, and it is the
property most worth setting.

An editing surface is a form, and a form that runs the full width of a page puts
the :guilabel:`Edit` button belonging to a value at the far edge of the screen,
far from the value it edits. The cap keeps the two together. Raise it for a
layout that gives the plugin a wide column of its own, lower it for a narrow one,
and set it to :code:`none` to let the surface fill whatever contains it.

..  _configuration-styling-typeface:

The typeface is inherited on purpose
====================================

:css:`--frontend-edit-font-family` is :code:`inherit`, and leaving it that way is
recommended. The surface is part of a page the site designed; a component that
arrives with a typeface of its own announces itself as a foreign body in a design
it knows nothing about. The properties above carry structure, weight and rhythm —
the site keeps the voice.

..  warning::

    A **web font from another origin will not load**, whatever is configured.
    The extension ships a Content Security Policy that permits resources from the
    site's own origin only, and fonts are covered by it. A font served from the
    installation itself is unaffected; one from a font CDN is refused by the
    browser.

    See :ref:`configuration-csp` for what the policy declares, and for how to
    relax or disable it.

..  _configuration-styling-control-contrast:

A control edge carries a contrast requirement
=============================================

Three properties draw borders, and they are three because only one of them is
covered by an accessibility requirement.

:css:`--frontend-edit-color-border-control` draws the resting edge of everything
operable — a button, an input, a select, a textarea. On this surface the fill of
a control differs from the page behind it by barely more than one to one, so that
edge is the only thing saying the control is there. WCAG 2.2 success criterion
1.4.11 (*Non-text Contrast*, level AA) asks for **3:1** on exactly that kind of
information, measured against both the fill the border encloses and the surface
behind it.

The shipped defaults meet it in both colour schemes with margin. An override does
not automatically:

..  code-block:: css

    /* Fails the criterion at 1.6:1 — the control disappears at rest. */
    modern-extbase-frontend-edit-profile {
        --frontend-edit-color-border-control: #e0e0e0;
    }

:css:`--frontend-edit-color-border` is decoration — separators, the child marker,
the image frame, the dialog, the state badge. It is deliberately **below** the
threshold, because a hairline held to a control's contrast turns the surface into
a stack of boxes, and none of those elements identifies a control.

:css:`--frontend-edit-color-border-strong` is the step past the resting edge: the
same borders under the pointer. Overriding the control edge without moving this
one too can invert the pair, and a hover state weaker than the resting state
reads as the control going quiet when it is reached.

..  note::

    Configuring :css:`classes.button` or :css:`classes.control` hands the control
    border to the site's own stylesheet, and these properties then draw nothing.
    The requirement does not move with them — it applies to whatever paints the
    edge. See :ref:`configuration-component`.

Dark colour schemes
===================

Eleven of the colour properties are redefined under
:css:`@media (prefers-color-scheme: dark)`, so a page that follows the operating
system setting gets a dark surface without configuring anything.

That is a courtesy, not a claim to support every dark theme. A site that switches
theme by some other means — a class on :html:`<html>`, a data attribute, a user
setting — sets the properties directly, and a direct declaration beats both
branches of the media query:

..  code-block:: css

    html[data-theme='dark'] modern-extbase-frontend-edit-profile {
        --frontend-edit-color-surface: #1b2126;
        --frontend-edit-color-border: #3a4249;
        --frontend-edit-color-muted: #9aa4ac;
    }

What cannot be changed this way
===============================

The properties cover appearance, not structure. Changing where the label sits
relative to its value, adding an element, or reordering the actions is a change
to the component, not to a stylesheet — the markup is generated by the component
itself, and CSS can restyle an element but cannot add, remove or reorder one.

The server rendered markup **outside** the component is ordinary Fluid and
ordinary light DOM: it can be restyled with plain CSS and replaced partial by
partial, which is described in :ref:`configuration-templates`.
