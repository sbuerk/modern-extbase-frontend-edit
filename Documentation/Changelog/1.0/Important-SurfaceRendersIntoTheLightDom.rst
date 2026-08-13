..  include:: /Includes.rst.txt

..  _important-light-dom:

=========================================================
Important: The editing surface renders into the light DOM
=========================================================

Description
===========

The editing surface used to be drawn inside a shadow root. It now renders into
the page like ordinary markup.

This was done so that a site can style the surface with its own rules: no
selector crosses a shadow boundary, so a theme's :css:`.button` could never
reach a button the surface drew, and the only styling interface was a CSS custom
property. That interface still exists and is unchanged.

Impact
======

**A site's stylesheet now applies to the surface.** This is the point of the
change, and it cuts both ways: rules written for the rest of the site reach the
surface whether or not that was intended. Every element the surface draws carries
a class prefixed :css:`frontend-edit-`, which is what a rule should be written
against.

**The stylesheet is no longer optional.** The appearance used to ship inside the
component, so a page that failed to load
:file:`frontend-edit.css` still rendered a coherent surface. It does not any
more. The file is emitted by the plugin's own template, so this only matters for
a template that renders the plugin without its assets.

..  seealso::
    :ref:`configuration-styling`, :ref:`configuration-component`
