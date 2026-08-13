# Styling the editing surface

How the editing surface is styled, why the values live where they live, and what
a site has to do to make it look like the rest of its pages.

Everything here is in the one emitted stylesheet,
`Build/Sources/Css/frontend/frontend-edit.css`.

## The surface renders into the light DOM

It used to render into a shadow root, and that decision was reversed
deliberately. The reasoning of both positions is kept, because the trade is real
and a reader deciding whether to copy this approach needs to see it.

**What the shadow root bought.** The component could not be broken by a theme it
knew nothing about. Its class names could not collide with anything. A site
could restyle it only through custom properties, which are the one thing that
crosses the boundary, and that was the whole theming interface.

**What it cost, and why the cost won.** A boundary that cannot be crossed cannot
be crossed *in either direction*. Three things this proof of concept has to
demonstrate were impossible behind it:

- A site cannot style the surface with its own rules. A theme's `.button` can
  never reach a button inside a shadow root, so the surface can only ever look
  like itself in a page that looks like something else.
- A project cannot add a class to a control. A class inside a shadow root is not
  selectable from outside it, so per-element class configuration would be inert.
- Form controls cannot look like the site's other forms without every rule of
  the site's form styling being restated inside the component.

`::part()` was considered and closes only the first of the three: it exposes an
element to be styled, and a theme still cannot apply its **existing** rules to
it. Making the surface match a design system would still mean copying that design
system into the component.

So the components override `createRenderRoot()` to return `this`, and the page's
CSS applies to everything they draw. **A host page can now break the surface**,
exactly as it can break any other markup on the page. That is the trade, stated
rather than hidden.

### What this changed, beyond the boundary itself

Four consequences, three of which were found by running the suite rather than by
reasoning about them.

**The stylesheet is no longer optional.** Lit only adopts `static styles` into a
shadow root, so under light DOM it is silently ignored. The whole appearance is
now in `frontend-edit.css`, and a page that fails to load it renders unstyled
markup. This reverses the reasoning in the next section, which is kept below
because it is exactly why the old arrangement existed.

**Every rule had to be scoped.** Inside a shadow root `button { … }` meant
"every button of this component". In the light DOM the identical rule means
**every button on the page**, the site's own included. Every selector in
`frontend-edit.css` is a descendant of `modern-extbase-frontend-edit-profile`,
and nothing may be written unscoped.

**Every class had to be prefixed.** `.field`, `.record` and `.state` are names
any site might already use — the development site package defines `.form-field`
and `.card` itself. They are all `frontend-edit-` prefixed now, which is also
what gives a project a stable hook to style against.

**`id` had to become unique per instance.** In a shadow root `id="label"` was
scoped to that root and every field could use it. In the light DOM all
twenty-six fields share one document, so a fixed `aria-labelledby` resolves to
the *first* field's label for every field on the page. Nothing throws, no test
fails, and a screen reader reads the wrong label for all but one field. Both
field elements carry a module level counter for this; scope and field name would
not do, because a profile has one `line1` and four addresses have four.

### The element has to take over from the server rendering

This one was a visible defect, and the acceptance suite caught it immediately.

The custom element wraps the server rendered profile — that markup is the
no-JavaScript view. Under a shadow root, hiding it cost no code at all: light DOM
children are not rendered unless a `<slot>` asks for them, so `render()` returned
a `<slot>` while unenhanced and returned none once it had a profile.

There is no equivalent in the light DOM, and nothing happens implicitly.
`lit-html` **inserts** its parts into the container and leaves whatever is
already there, so the server rendered profile stayed exactly where it was and
the page showed the profile twice — once static, once live, with the static copy
going stale on the first save.

`ProfileEditElement` therefore removes those children explicitly, in
`initialize()` and only once enhancement is certain. Removing them any earlier
would take the fallback away from a visitor whose element is about to decide it
*cannot* enhance, which is the one case the fallback exists for. It is a removal
rather than a hide, because a hidden copy of the whole profile would still be in
the document showing the values the page was loaded with.

## The tokens still exist, and still live on the outer element

Custom properties are no longer the *only* interface, but they are still the
cheapest one, and the rule about where they are declared is unchanged:

```css
modern-extbase-frontend-edit-profile {
    --frontend-edit-color-accent: #b8003c;
}
```

They are declared on that element and on nothing below it. Properties inherit,
so one declaration reaches the whole surface; declaring them again on a child
would be a **direct hit** on that child and would beat the inherited, overridden
value, so a site's override would recolour the frame and nothing inside it.

## Why the tokens used to be in the component, and no longer are

Worth keeping, because it documents what the light DOM gave up.

The tokens shipped inside the component so that the stylesheet could be a **pure
addition**: a template that never called the asset ViewHelper, or a cache that
served a stale `<head>`, left the page without it, and if the stylesheet had
owned the tokens every `var()` would have been invalid at computed value time —
unstyled text with nothing to indicate why.

That protection is gone, and it could not be kept: under light DOM the component
has no stylesheet of its own to put them in. The stylesheet is emitted by
`<f:asset.css>` from the plugin's own template, so it arrives whenever the plugin
does, and the failure mode is now "no CSS at all" rather than "half a surface".

## What is deliberately not a token

**The typeface.** `--frontend-edit-font-family` is `inherit` and is meant to stay
that way. This is an extension rendering into somebody else's page; a surface
that arrives with its own display face announces itself as a foreign body in a
design it knows nothing about. The tokens carry structure, weight and rhythm, and
the host site keeps the voice.

There is also no web font, and there could not be one: `font-src` is not declared
in `Configuration/ContentSecurityPolicies.php` and therefore falls back to
`default-src`, so a font from another origin is refused by the policy the
extension ships. That is the intended outcome, not an obstacle — the manual
documents it in
[Content Security Policy](../../Documentation/Configuration/ContentSecurityPolicy.rst).

## The rhythm, and the row that made it possible

A field is a **row** — label, value, and the action belonging to it — and it used
to be a stack. That was the single largest thing wrong with the surface, and it
was found by measuring rather than by looking:

|                             | before  | after   |
|-----------------------------|---------|---------|
| The whole surface           | 3087 px | 2084 px |
| One field element           | 76 px   | 36 px   |
| 26 fields, as a share of it | 64%     | 45%     |

Each of those 76 pixels showed about twenty pixels of text. A row cannot be
shorter than its tallest control, and the control is a 36 pixel touch target that
is not negotiable, so putting the label *beside* it rather than above it is the
only way to recover the height. Sitting the action next to the value it acts on
came free with it.

**`flex-wrap`, not a query.** When the container is too narrow for the label
column and the body side by side, the body drops to its own line and the old
stack comes back. That responds to the width of the **container**, which is what
a plugin dropped into an unknown column needs — a viewport media query would be
answering a different question. Container queries would say it more directly and
are unavailable: the import map browser floor includes Firefox 108, and
`@container` needs 110.

### The box model was a defect, and the comment above it was wrong

The style layer claimed from the start that a value and the control replacing it
occupy the same box, so that entering edit mode does not reflow the row. It did
reflow, on every field, and nobody noticed because nothing measured it. There
were **two** causes, and the second only became visible after the first was
fixed:

| Element        | `box-sizing` | `min-height` | Resolved height |
|----------------|--------------|--------------|-----------------|
| `.field-value` | content-box  | 2.25rem      | 48 px           |
| `button`       | border-box   | 2.25rem      | 36 px           |
| `input`        | content-box  | 2.25rem      | 50 px           |

`min-height` applies to the *content* box, so the 6 px vertical padding and the
1 px border were adding to the 36 px target rather than fitting inside it —
except on `button`, which the **user agent stylesheet** already gives
`box-sizing: border-box` while giving `input`, `select` and `textarea`
`content-box`. One declaration of `min-height` therefore produced two different
heights depending on which element it landed on, and a field was 36 px while read
and 50 px while edited.

Setting `box-sizing: border-box` on the value and on all four controls makes
every one of them exactly `--frontend-edit-control-min-height`, and makes the
comment true. `Tests/Acceptance/Frontend/FieldLayout.spec.ts` now measures it —
it was written against the broken layout and failed, which is how the second
cause was found at all.

### Four steps, named by what they separate

| Token                         | Separates            | Value |
|-------------------------------|----------------------|-------|
| `--frontend-edit-gap-within`  | a label from a value | 4 px  |
| `--frontend-edit-gap-field`   | two fields           | 8 px  |
| `--frontend-edit-gap-record`  | two records          | 16 px |
| `--frontend-edit-gap-section` | two collections      | 24 px |

The layout uses only these four; the raw `--frontend-edit-space-*` scale exists to
give them values. The first version of this layer reached into that scale at every
call site, and the result was a surface where the distance between a label and its
value was almost the distance between two records — nothing grouped, and it read
as one long list. Each step is at least double the one before it, which is what
makes a group legible: the eye reads the smaller gap as "together" only when the
larger one is unmistakably larger.

## The measure

`--frontend-edit-measure` caps the surface at `48rem`, and it is the token most
likely to be overridden.

It exists because of something only a screenshot showed. Without it the surface
is as wide as the content area it sits in, `.field-value` stretches to fill the
row, and the `Edit` button belonging to a value is pushed to the far edge — on a
full width page, a thousand pixels from the value it edits, with the eye having
to travel the whole line to connect the two. A form has a measure. The first
version of this layer did not, it looked wrong, and the generated documentation
screenshots are what made it obvious.

## Colour, and the dark scheme

The light values are the defaults. A `@media (prefers-color-scheme: dark)` block
redefines eight of them, which is a courtesy for a host page that follows the
system setting rather than a claim to support every dark theme — a site that
themes itself by some other means overrides the tokens directly, and that beats
both branches.

`color-mix()` would express "a border a little lighter than the text" far better
than eight literals, and it is not used: the browser floor of the import map
mechanism is `chrome89 / firefox108 / safari16.4`, and `color-mix()` needs
Chrome 111 and Firefox 113. It cannot be lowered by the build the way nesting can.

## The button hierarchy is an attribute, not a class

Three levels, and the default is the unmarked one:

| Variant     | Meaning                           | Buttons                     |
|-------------|-----------------------------------|-----------------------------|
| `primary`   | commits a pending change          | Apply, Save all fields, Add |
| *(default)* | changes what the surface is doing | Edit, Cancel, Move, Hide    |
| `danger`    | destroys a record or a file       | Remove                      |

It travels in `data-variant` rather than in `class`, and that follows from the
section below: class names here are structural and the acceptance suite selects
through them. Putting a presentational token in the same attribute would place a
rename of an appearance concern next to a selector a test depends on.
`data-variant` can be renamed freely; `.field-value` cannot.

**Only two levels are marked, and that is the restraint.** `primary` is the one
filled thing in a row — filled rather than tinted, because among four bordered
buttons a tint is a shade and not a hierarchy. `danger` states itself in colour
and does not fill until the pointer is on it, because the row it lives in (move,
hide, remove) is one a reader uses for the other three far more often, and a
permanently red button shouts at somebody who is not going to press it.

A fourth, quieter level for `Cancel` was considered and rejected: it would make
`Apply` / `Cancel` read as one real button beside one hint, and cancelling is an
ordinary thing to want.

**The mapping is tested, the appearance is not.**
`Tests/Acceptance/Frontend/ButtonHierarchy.spec.ts` enumerates every button the
surface draws and asserts the complete mapping, so a button added later fails
until somebody decides what it is. It asserts nothing about colour — the
stylesheet may change what `primary` looks like without touching the spec.

## The icons are inline SVG, and that was the only option

`Build/Sources/TypeScript/frontend/icon/icons.ts` draws ten glyphs by hand. Two
alternatives were available and both are closed:

- **An icon font or an SVG sprite from a CDN** is refused by the Content Security
  Policy this extension declares, which permits the installation's own origin
  only.
- **TYPO3's `IconFactory`, through a ViewHelper**, cannot reach these buttons.
  Every action is rendered *client side*, from JSON handed over
  in an attribute — by the time a button exists, Fluid has long finished.

Inline SVG touches no CSP directive at all (markup is not a fetch), costs no
request, and inherits `currentColor`, so a glyph follows whatever colour its
button already has — including the danger red and the filled primary.

**Icons are decoration, and the label is never in `aria-label`.** Every glyph is
`aria-hidden="true"` and `focusable="false"`, and every button carries its
translated text in a `<span class="button-label">` — visible in most places,
visually hidden in the record toolbars.

That distinction is load bearing rather than pedantic. The tempting
implementation of an icon-only button is `aria-label` and no text, which reads
correctly to a screen reader and leaves the button with **no `textContent` at
all** — silently breaking `ButtonHierarchy.spec.ts`, which enumerates buttons by
their text. A visually hidden span satisfies the accessible name *and*
`textContent`, and degrades into visible text if the stylesheet never loads.
`Tests/Acceptance/Frontend/ActionIcons.spec.ts` asserts exactly this, and was
shown to fail against the `aria-label` version.

**Only the record toolbars drop their labels** — move, hide, remove, repeated
once per child. Four wide text buttons per child were the heaviest thing on the
surface, row-level actions are the case where an icon alone is understood, and it
is the treatment the TYPO3 backend gives the equivalent controls in a record
list. Everything else keeps icon *and* text.

## The file input is a label wrapping a hidden input

`<input type="file">` is the one form control whose box the browser owns. Its
inner button cannot be reached from a stylesheet — `::file-selector-button`
reaches *a* button but not the layout around it — and it draws **"No file
chosen"** beside itself, which cannot be removed at all.

Here that text was not merely ugly, it was **permanently false**: the component
clears the input the moment it reads a file, so the control said "no file chosen"
including immediately after one had been chosen and uploaded.

The fix is the standard one, and it is worth knowing why each half is needed:

```html
<label class="file-picker" ?data-disabled="…">
    <input class="field-control visually-hidden" type="file" …>
    <svg class="icon" aria-hidden="true">…</svg>
    <span class="button-label">Choose image</span>
</label>
```

- **The input is hidden, not replaced.** Every native behaviour stays — the
  dialog opens on click and on Enter, the file lands in `FormData`, and
  Playwright's `setInputFiles()` still finds a real `input[type=file]`. A button
  that called `.click()` on a hidden input would be re-implementing the platform.
- **It is `visually-hidden`, not `display: none`.** A `display: none` input is
  not focusable, and the control would drop out of the tab order entirely. The
  ring is drawn on the label with `:focus-within`, because the focus lands on the
  input inside it.
- **The label states which write it performs** — `Choose image`, or
  `Replace image` once one is stored. Picking a file *is* the write here; there
  is no apply step, and `Choose` beside a stored portrait would understate what
  pressing it costs.
- **`data-disabled` rather than `:has(input:disabled)`.** A `<label>` cannot
  carry `:disabled`, and `:has()` is above the browser floor the import map
  mechanism sets — Firefox needs 121, the floor is 108. The component states the
  condition it already knows.

`.file-picker` is styled in `controls.ts` alongside `button`, not in the image
component: it is a control, and that is the module that makes a control look like
one.

## The appearance is guarded by seven baselines

`Tests/Acceptance/Visual/surface.visual.ts` compares seven components against
committed PNGs: a field at rest, a field being edited, a rejected field, a child
header, a hidden child header, the image row, and a field in a narrow column.

```bash
Build/Scripts/runTests.sh -s visualRegression                      # the gate
Build/Scripts/runTests.sh -s visualRegression -- --update-snapshots  # re-record
```

**This was deliberately refused three times** while the surface was being
designed, and the reason is worth keeping: a baseline freezes an appearance, and
freezing one nobody is happy with turns every improvement into a wall of red
diffs to approve — which trains a reviewer to approve them unread, at which point
the suite costs time and catches nothing. It became worth having once the design
stopped moving.

Four things about it are decisions rather than defaults:

- **One baseline per component, never a full page.** A 2000 pixel image is a
  large binary that fails on any change anywhere and names none of them. All
  seven together are 56 kB.
- **`maxDiffPixels: 60`, measured not estimated.** Raising
  `--frontend-edit-border-width` from `1px` to `2px` fails all seven, and the
  smallest of them differs by 188 pixels. A change small enough to slip through
  is smaller than a border.
- **`deviceScaleFactor: 1`**, unlike the documentation screenshots. A 2×
  baseline is four times the bytes and four times the antialiased edges that can
  differ between two machines, and nothing here is read by a human.
- **No `{platform}` in the baseline path.** Playwright puts it there by default,
  which would invite a second set recorded on a host — and a host run is exactly
  the one with different fonts. One set, recorded in the container, is the point.

**No dark scheme baseline.** `prefers-color-scheme` can be emulated and the eight
dark tokens would then be pinned, but a second set doubles what a restyle has to
re-record, and the dark values are a courtesy rather than a supported theme.
Named rather than hidden.

**And the manual is guarded separately.**
`Build/Scripts/runTests.sh -s checkDocumentationScreenshots` compares the six
screenshots `Documentation/` embeds against the surface they claim to show. It
covers two states these baselines deliberately do not — the anonymous visitor and
the JavaScript-disabled fallback — so a styling change that only affects those
still fails something. Together the two suites are why there is **no** baseline
for the anonymous and server rendered states here: that would be a second copy of
coverage that already exists, and two more images to re-record on every restyle.
→ [Acceptance tests](../testing/acceptance-tests.md#what-checks-the-generator)

## Class names are structural, not presentational

`.frontend-edit-field-value`, `.frontend-edit-field-control`,
`.frontend-edit-field-errors` and `.frontend-edit-record` are addressed by the
acceptance suite — `Tests/Acceptance/Support/profileEditPage.ts` selects through
them precisely because they describe structure and not appearance. Renaming one
is a test change, not a styling change.

**Every one of them is prefixed**, and that became load bearing with the light
DOM rather than merely tidy: unprefixed, `.field` and `.record` would collide
with the surrounding site in both directions — its rules would reach the surface
by accident, and the surface's rules would reach its markup.

## What this layer does not do yet

- **No motion beyond two colour transitions.** There is one duration token, and
  `prefers-reduced-motion` sets it to `0ms` — one declaration rather than an
  `!important` sweep.

  Those two transitions had a consequence nobody predicted: the documentation
  screenshot generator photographed three shots *during* the 120ms fade, and the
  manual carried a `Cancel` button caught half way through it for eight pull
  requests. Anything that takes a picture of this surface has to disable
  animations, and both suites that do now say so.
- **Legibility is still nobody's assertion.** The visual suite pins that the
  surface has not *changed*; whether a colour has enough contrast, whether the
  focus ring survives a dark host page, and whether the dark scheme is usable at
  all are still a person's judgement. No baseline is recorded for the dark
  scheme — see below.
