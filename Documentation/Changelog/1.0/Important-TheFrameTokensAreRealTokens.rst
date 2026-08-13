..  include:: /Includes.rst.txt

..  _important-outline-tokens:

======================================================================
Important: The surface frame is drawn from tokens the surface declares
======================================================================

Description
===========

The dashed frame that marks an upgraded editing surface is drawn from
:css:`--frontend-edit-outline-width` and :css:`--frontend-edit-outline-color`.
Both were documented as design tokens and neither was declared: they existed
only as fallback values inside the :css:`var()` that read them.

Both are declared in the token block now, beside every other token, and the
colour follows :css:`--frontend-edit-color-accent` rather than repeating a
literal.

Impact
======

**Overriding either continues to work exactly as before.** A site that already
declares one of them on :css:`modern-extbase-frontend-edit-profile` needs no
change.

**The frame follows the accent, including in the dark scheme.** The hardcoded
fallback was the light accent, so a surface rendered under
:css:`prefers-color-scheme: dark` drew its frame in a blue that belonged to the
light palette. A site that had recoloured the accent and expected the frame to
follow it now gets that, which is a visible change on a page it had not been
worth reporting as a defect before.

**They can be found by reading the stylesheet.** A token that appears only as a
:css:`var()` fallback is one a reader cannot discover from the block that is
supposed to list every token, whatever the manual says about it.

..  seealso::
    :ref:`configuration-styling`
