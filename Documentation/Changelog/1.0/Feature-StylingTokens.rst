..  include:: /Includes.rst.txt

..  _feature-styling-tokens:

==================================
Feature: The surface can be themed
==================================

Description
===========

The editing surface is styled from a set of CSS custom properties instead of
values written into the components. Every colour, distance, radius, duration and
width it uses is now a property declared on the custom element, and a site
overrides one by setting it on that element from its own stylesheet.

This is the only way the surface can be restyled at all: it is drawn inside a
shadow root, which no selector reaches, and a custom property is the one thing
that crosses that boundary.

..  code-block:: css

    modern-extbase-frontend-edit-profile {
        --frontend-edit-color-accent: #b8003c;
        --frontend-edit-measure: 60rem;
    }

The complete list is in :ref:`configuration-styling`.

What changed in the appearance
==============================

The surface was previously laid out but not styled — buttons were user agent
buttons at a different height than the controls beside them, and the error
colour was written three times in three files.

*   Buttons and controls share one box: the same height, border, radius and
    focus ring.
*   Buttons carry emphasis. The one that commits a pending change —
    :guilabel:`Apply`, :guilabel:`Save all fields`, :guilabel:`Add` — is filled
    in the accent colour, and :guilabel:`Remove` is labelled in the danger
    colour and fills only under the pointer. Everything else is the plain
    button.
*   The surface is capped at :css:`--frontend-edit-measure`, so the
    :guilabel:`Edit` button of a value no longer sits at the far edge of a wide
    page.
*   A rejected control and its focus ring are drawn in one colour rather than
    two, so the field reads as wrong rather than as focused.
*   Labels, captions and the state badge are set quieter than the values they
    describe.
*   A dark colour scheme is provided for pages that follow the system setting.

Impact
======

An installation that has not styled the surface needs to do nothing.

The typeface is unchanged and is still inherited from the page: the extension
ships no font and cannot load one from another origin, because the Content
Security Policy it declares permits the installation's own origin only. See
:ref:`configuration-csp`.
