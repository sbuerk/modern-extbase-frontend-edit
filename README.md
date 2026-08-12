# TYPO3 extension `modern_extbase_frontend_edit`

> [!CAUTION]
> **This is a proof of concept, not a product.** It exists to answer one
> question — *can Extbase entities with relations be managed from the frontend
> with a modern, accessible, progressively enhanced interface?* — and to be read
> while answering it.
>
> **Do not copy it into a production extension, whole or in parts.** Several
> decisions in it are deliberate trade-offs that are wrong outside this context,
> and they are documented as such rather than fixed. Read
> [what it deliberately does not do](#what-it-deliberately-does-not-do) before
> reusing anything.

Editing a profile record and its child collections directly on the page: every
field, every address, every e-mail address and the profile image, saved over
AJAX without a page reload, from a
[lit](https://lit.dev) web component that enhances markup the server already
rendered.

- **Package name**: `sbuerk/modern-extbase-frontend-edit`
- **Extension key**: `modern_extbase_frontend_edit`
- **Repository**: https://github.com/sbuerk/modern-extbase-frontend-edit
- **License**: GPL-2.0-or-later

## What it demonstrates

| Question it answers                                                        | How                                                                                                                                                                   |
|----------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Can a visitor edit an Extbase aggregate and its relations in the frontend? | Three plugins — a list, a detail view and an edit surface — over one `Profile` record with two inline child collections and a FAL image.                              |
| Without a full page form?                                                  | Per field and whole record saves against JSON endpoints, plus add, remove, reorder and hide for each child collection.                                                |
| Without breaking when JavaScript does not load?                            | The server renders the whole record inside the custom element. The component replaces it once it upgrades; until then, and forever without it, the plain view stands. |
| Who is allowed to write?                                                   | The record is owned by a frontend user. Every endpoint resolves the profile from the session, never from a uid the client supplied.                                   |
| How does it persist?                                                       | Extbase `PersistenceManager` with a DTO to model mapping service, deliberately **not** `DataHandler`.                                                                 |
| What stops a forged request?                                               | A TYPO3 request token, verified on every write, plus validation driven by rule sets shared between the endpoints and the surface.                                     |
| Does it run on both current core versions?                                 | v13 and v14 from one code base, with version differences split into separate classes rather than conditionals.                                                        |

## What it deliberately does not do

These are the reasons it must not be copied without reading. Each is a decision,
not an oversight, and each is documented where it is made:

- **Writes bypass `DataHandler`.** That means no `sys_history`, no hooks and no
  reference index update. For a proof of concept about Extbase persistence that
  is the point; for a production extension it is usually a defect.
- **Editing is refused while a workspace is active**, and the surface says so
  before the visitor types rather than after. Versioning a record is
  `DataHandler`'s job, so workspace editing is out of scope.
- **Only default language records are edited.** No translation is created.
- **Last write wins.** There is no optimistic locking and no rate limiting.
- **One image, no cropping, no metadata editing.**

## Compatibility

| Branch | Extension | TYPO3     | PHP       |
|--------|-----------|-----------|-----------|
| main   | 1.x       | v13 / v14 | 8.2 - 8.5 |

## Installation

```bash
composer require sbuerk/modern-extbase-frontend-edit
```

As long as no stable version has been released, require the development version
of the main branch explicitly:

```bash
composer require sbuerk/modern-extbase-frontend-edit:^1.0@dev
```

This additionally requires `minimum-stability: "dev"` together with
`prefer-stable: true` in the root `composer.json` file.

Installing it is not enough to see anything: the extension needs a storage page,
the plugins placed on pages, and a frontend user owning a profile record. The
[installation chapter](Documentation/Installation/Index.rst) has the full list.

## Documentation

| For                        | Where                                                         |
|----------------------------|---------------------------------------------------------------|
| Users and integrators      | [`Documentation/`](Documentation), rendered to docs.typo3.org |
| Developers and maintainers | [`docs/`](docs/Index.md)                                      |
| Contributors, entry point  | [`CONTRIBUTING.md`](CONTRIBUTING.md)                          |
| AI coding agents           | [`AGENTS.md`](AGENTS.md)                                      |

```bash
# Render once, as CI does. Must pass without errors.
Build/Scripts/runTests.sh -s renderDocumentation

# Serve it while writing, re-rendering on every save, on port 1337.
Build/Scripts/runTests.sh -s watchDocumentation
```

The rendered output is written to the git-ignored `Documentation-GENERATED-temp/`
directory.

## Development

All tests and quality tools run in containers through the
[`Build/Scripts/runTests.sh`](Build/Scripts/runTests.sh) wrapper. The only
requirement on the host is a container runtime — **podman** (preferred) or
**docker**.

```bash
# Install dependencies for TYPO3 v13 on PHP 8.2 (default matrix).
Build/Scripts/runTests.sh -t 13 -p 8.2 -s composerUpdate

# Quality gates.
Build/Scripts/runTests.sh -s cgl -n
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s lintPhp

# Tests.
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional -d sqlite
Build/Scripts/runTests.sh -s acceptance

# All available options.
Build/Scripts/runTests.sh -h
```

Everything has to pass for **both** TYPO3 v13 and v14, each after the matching
`composerUpdate` — see
[Dual core setup](docs/development/dual-core-setup.md).

→ [`CONTRIBUTING.md`](CONTRIBUTING.md) for the contribution workflow ·
[`docs/`](docs/Index.md) for the full developer documentation

## License

This extension is published under the [GPL-2.0-or-later](LICENSE) license.
