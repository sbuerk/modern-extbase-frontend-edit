..  include:: /Includes.rst.txt

..  _important-the-focus-ring-follows-the-colour-scheme:

===================================================
Important: The focus ring follows the colour scheme
===================================================

Description
===========

On a dark page the focus ring was drawn in the **light** accent colour, which
measured 2.80:1 against the page — below the 3:1 that WCAG 2.2 success criterion
1.4.11 (*Non-text Contrast*, level AA) asks of a focus indicator. It is now drawn
in the scheme's own accent, at 6.54:1.

The defect was in the development site package rather than in the extension, but
it is described here because the shape of it is worth knowing to anyone theming
the surface — the same mistake in a project's own stylesheet produces the same
result, and nothing reports it.

A custom property is substituted at **computed value time on the element that
declares it**. The theme declared its focus colour once, on :css:`:root`:

..  code-block:: css

    :root {
        --c-accent: #2563a8;
        --focus-color: var(--c-accent);   /* resolves to #2563a8 right here */
    }

    body[data-color-scheme='dark'] {
        --c-accent: #6ba4e0;              /* --focus-color does not follow */
    }

What inherits from :css:`:root` down to :html:`<body>` is the *resolved colour*,
not the reference. Redefining :css:`--c-accent` further down therefore changes
everything that reads :css:`--c-accent` directly, and changes nothing that read
it one element higher.

Impact
======

**An installation that has not styled the surface needs to do nothing.** The
extension's own tokens were never affected: it declares
:css:`--frontend-edit-focus-color` and its dark override of
:css:`--frontend-edit-color-accent` on the *same* element, so the cascade picks
the dark value before the substitution happens.

An installation whose theme derives one custom property from another should
check that the derived one is restated in every scheme block, or declared
somewhere the scheme has already been decided. The symptom is a colour that is
correct in one scheme and stale in the other, and it is invisible in the
stylesheet — the declaration reads exactly like one that works.

See :ref:`configuration-styling-control-contrast` for the contrast requirement
that applies to a control's edge, which a focus indicator shares.
