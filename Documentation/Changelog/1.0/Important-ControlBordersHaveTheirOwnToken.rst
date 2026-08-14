..  include:: /Includes.rst.txt

..  _important-control-borders-have-their-own-token:

===============================================
Important: Control borders have their own token
===============================================

Description
===========

The border of an operable control is now drawn from
:css:`--frontend-edit-color-border-control` instead of from
:css:`--frontend-edit-color-border`, which keeps the decorative borders.

The reason is a measurement rather than a preference. WCAG 2.2 success criterion
1.4.11 (*Non-text Contrast*, level AA) asks for 3:1 on the visual information
required to identify a user interface component. On this surface the fill of a
button or an input differs from the page behind it by 1.1:1 to 1.3:1, so the
border is the only thing that identifies it — and one token drew both that border
and the hairline between two sections, at **1.6:1** against its own fill in the
light scheme and **1.4:1** in the dark one. A control at rest was, in the literal
sense, not visible as a control.

Three roles, and only the middle one carries the requirement:

..  list-table::
    :header-rows: 1

    *   -   Property
        -   Draws
        -   Owes 3:1
    *   -   :css:`--frontend-edit-color-border`
        -   Separators, the child marker, the image frame, the dialog, the badge
        -   No — none of them identifies a control
    *   -   :css:`--frontend-edit-color-border-control`
        -   The resting edge of a button, input, select and textarea
        -   **Yes**
    *   -   :css:`--frontend-edit-color-border-strong`
        -   The same edges under the pointer, and the add form outline
        -   No, but it has to stay past the resting edge

:css:`--frontend-edit-color-border-strong` changed value in both schemes as a
consequence. It used to be the step above a decorative hairline; it is now the
step above a control edge, and leaving it where it was would have made the hover
state the *weaker* of the two — a control appearing to go quiet when the pointer
reaches it.

Impact
======

**An installation that has not styled the surface needs to do nothing**, and will
see its buttons and inputs gain a visible edge in both colour schemes.

An installation that overrides :css:`--frontend-edit-color-border` and expects it
to reach the controls has to set :css:`--frontend-edit-color-border-control` as
well. Nothing breaks if it does not: the controls fall back to the shipped
default, which meets the criterion.

An installation that configures :css:`classes.button` or :css:`classes.control`
hands the control border to its own stylesheet. These properties then draw
nothing, and the 3:1 requirement applies to whatever does — it is a property of
the rendered page, not of this extension's tokens.

See :ref:`configuration-styling-control-contrast`.
