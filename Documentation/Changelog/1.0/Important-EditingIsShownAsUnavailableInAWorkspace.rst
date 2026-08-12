..  include:: /Includes.rst.txt

..  _important-editing-unavailable-in-a-workspace:

=========================================================
Important: Editing is shown as unavailable in a workspace
=========================================================

Description
===========

The :guilabel:`Profile edit` plugin now says so **before** anything is typed
when a workspace is active. It shows the profile — the fields, both lists and
the image, including the entries the owner has hidden — under one sentence:

..  code-block:: text

    Your profile is shown as it appears in this workspace. Editing is only
    available in the live workspace.

No editing controls are rendered in that state, and the JavaScript and the
stylesheet of the editing surface are not loaded at all.

Nothing about what the extension *allows* changed. Saving from a workspace was
already refused, and is still refused by the server for any request that reaches
it. What changed is when the person editing finds out: previously the surface
looked fully editable, and the refusal arrived only after a value had been typed
and :guilabel:`Apply` pressed.

Why editing is live only
========================

Saving a profile writes the record directly, without going through the TYPO3
data handler. That is what makes the editing surface fast and self-contained,
and it is also why a workspace cannot be supported: creating a draft version of
a record is precisely the work the data handler does. A save issued from a
workspace would therefore not produce a draft — it would change the **published**
record while the editor believed the opposite.

Refusing is the only correct behaviour, and showing the refusal in advance is
the honest form of it.

Impact
======

An integrator using workspaces should expect the plugin to be read only there.
There is no setting to change this, and enabling it would not be a
configuration decision but a different persistence layer.

For everyone else nothing changes: in the live workspace, which is where a
website user always is, the plugin behaves exactly as before.
