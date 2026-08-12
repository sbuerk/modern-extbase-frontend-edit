# Styling the editing surface

How the editing surface is styled, why the values live where they live, and what
a site has to do to make it look like the rest of its pages.

Everything here is in `Build/Sources/TypeScript/frontend/style/` and in the one
emitted stylesheet, `Build/Sources/Css/frontend/frontend-edit.css`.

## The problem this layer solves

The surface is drawn inside a shadow root. That is what keeps the component from
being broken by a theme it knows nothing about, and it is also what makes it
unreachable: a site cannot restyle it with a selector, because no selector
crosses the boundary. Left there, the component either imposes its own look on
every site that installs it or has no look at all.

Before this layer it had close to none. Each of the three components carried its
own copy of the field rules, every button said `font: inherit` and nothing else —
so a user agent button sat between two styled inputs at a different height — and
the error colour was written as a literal `#a4141a` in three files that had
already started to drift apart.

Custom properties are the one thing that does cross a shadow boundary, so they
are the whole interface: **every value the components use is a token, and a site
themes the surface by setting tokens.**

## Three modules, and what each is for

| Module        | Holds                                                               | Applied to     |
|---------------|---------------------------------------------------------------------|----------------|
| `tokens.ts`   | Every colour, distance, radius, duration and the measure. No rules. | `profileEdit`  |
| `controls.ts` | Buttons, inputs, the focus ring, the invalid ring.                  | all three      |
| `field.ts`    | The label / value / actions / errors chrome of a field.             | the two fields |

They are `CSSResult` values, not stylesheets: lit takes an array for
`static styles` and adopts each once per component, so importing the same module
into three elements costs one constructed stylesheet, not three.

## Only the outer element declares the tokens

`tokens` is part of `ProfileEditElement.styles` and of no other component. This
is load bearing, and the mistake it prevents is invisible until somebody tries to
theme the thing.

Custom properties inherit down the flattened tree, so a property set on
`modern-extbase-frontend-edit-profile` reaches every shadow root below it. A site
overrides one by declaring it on that element:

```css
modern-extbase-frontend-edit-profile {
    --frontend-edit-color-accent: #b8003c;
}
```

That works because a declaration in the **outer** tree beats a `:host`
declaration in the inner tree — the shipped value is a default, which is the
relationship wanted.

But the override lands on the *profile* element only, and reaches the field and
image elements by inheritance. If those elements also declared the property on
their own `:host`, that declaration would be a **direct hit** on the element and
would beat the inherited, overridden value. The frame would change colour and
every field inside it would keep the shipped default. Declaring the tokens once,
at the top, is what makes the override reach the whole surface.

## Why the tokens are not in the stylesheet

The obvious alternative is `frontend-edit.css`, which is greppable without a
build and is where a CSS author would look first. It was rejected.

That stylesheet is an **optional page asset**. A template that never calls the
asset ViewHelper, or a cache that served a stale `<head>`, leaves the page
without it — and if it owned the tokens, every `var()` in every component would
be invalid at computed value time. The surface would render as unstyled text with
nothing to indicate why. Shipped inside the component, the tokens arrive with the
code that consumes them, and the stylesheet is a pure addition that can go
missing without taking the surface with it.

The stylesheet does *read* the tokens — the dashed outline it draws around the
element is `var(--frontend-edit-outline-color)`. That is safe without a fallback
for a reason worth knowing: a rule applies to the same element that `:host`
declares the properties on, so the values are there whenever the element is
upgraded, and every declaration reading one is inside a block that
`&:not(:defined)` switches off while it is not.

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
  Every action is rendered *client side*, in a shadow root, from JSON handed over
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

## Class names are structural, not presentational

`.field-value`, `.field-control`, `.field-errors` and `.record` are addressed by
the acceptance suite — `Tests/Acceptance/Support/profileEditPage.ts` selects
through them precisely because they describe structure and not appearance.
Renaming one is a test change, not a styling change.

## What this layer does not do yet

- **No motion beyond two colour transitions.** There is one duration token, and
  `prefers-reduced-motion` sets it to `0ms` — one declaration rather than an
  `!important` sweep.
- **Nothing verifies the appearance.** The acceptance suite proves the surface
  still works, and one spec pins which button carries which emphasis — but that
  a colour is legible, that a focus ring is visible against a dark host page, or
  that nothing overlaps at 320 pixels is a person looking at the six generated
  screenshots. A visual regression suite would be the honest fix and does not
  exist.
