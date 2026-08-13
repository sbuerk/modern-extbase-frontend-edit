# Developer documentation

Technical documentation for developers working **on** this extension: how the
code base is structured, which rules apply to it, how to run the tooling and how
changes get released.

Documentation for people **using** the extension lives in
[`Documentation/`](../Documentation) and is rendered to docs.typo3.org.
[`README.md`](../README.md) is the short overview,
[`CONTRIBUTING.md`](../CONTRIBUTING.md) the entry point that links here.

## [Development](development/Index.md)

| Page                                                  | Contents                                                                     |
|-------------------------------------------------------|------------------------------------------------------------------------------|
| [Development environment](development/environment.md) | `runTests.sh`, container runtimes, suites and options.                       |
| [Dual core setup](development/dual-core-setup.md)     | Running against TYPO3 v13 and v14, and the rule that avoids false positives. |
| [Quality gates](development/quality-gates.md)         | Every gate and its configuration, PHPStan per core version, CI.              |
| [Brand assets](development/brand-assets.md)           | The extension icon, the logo, and why the mark exists twice.                 |

## [Architecture](architecture/Index.md)

| Page                                                                     | Contents                                                                                            |
|--------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| [Core version aware code](architecture/core-version-aware-code.md)       | `Classes/` vs `Core13/` vs `Core14/`, and how the right variant is selected.                        |
| [Dependency injection](architecture/dependency-injection.md)             | Symfony DI attributes, stateless services, the rules that apply.                                    |
| [Class design](architecture/class-design.md)                             | `final readonly`, method injection in abstract classes, data objects, the accepted PHPStan ignores. |
| [Version neutral attributes](architecture/version-neutral-attributes.md) | The Extbase attributes that cannot be written for v13 and v14 at once, and what to use instead.     |

## [Modern frontend editing](frontend-edit/Index.md)

| Page                                                                | Contents                                                                                                  |
|---------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| [Domain and schema](frontend-edit/domain-schema.md)                 | The three tables, their TCA, and why none of it needs a version conditional.                              |
| [Plugins and the Fluid layer](frontend-edit/plugins-and-fluid.md)   | The three plugins, their registration and settings, and the partial API.                                  |
| [The edit plugin](frontend-edit/edit-plugin.md)                     | The two editing modes, the client-rendered surface, degradation, the one document factory.                |
| [Persistence and sorting](frontend-edit/persistence-and-sorting.md) | What Extbase persistence does not do for us: sorting, orphans, hidden children, and the gaps that remain. |
| [AJAX transport](frontend-edit/ajax-transport.md)                   | Why a page type rather than eID or a middleware, the nine endpoints, the request token.                   |
| [Authorization](frontend-edit/authorization.md)                     | Ownership resolved from the session, and the security checklist.                                          |
| [DTOs and validation](frontend-edit/dto-and-validation.md)          | Rules as data, full versus partial validation, hydration, the mappers.                                    |
| [Image handling](frontend-edit/image-handling.md)                   | The two image endpoints, the upload rules, the read-side wrapper, replacement and cleanup.                |
| [Frontend assets](frontend-edit/frontend-assets.md)                 | Import maps in the frontend, mapping `lit`, the TypeScript toolchain and the gates it needs.              |

## [Testing](testing/Index.md)

| Page                                                      | Contents                                                               |
|-----------------------------------------------------------|------------------------------------------------------------------------|
| [PHPUnit configuration](testing/phpunit-configuration.md) | Where the config comes from, deliberate deviations, strictness policy. |
| [Unit tests](testing/unit-tests.md)                       | Layout, conventions, core version aware tests.                         |
| [Functional tests](testing/functional-tests.md)           | Base test case, databases, container assertions.                       |
| [Fixture extensions](testing/fixture-extensions.md)       | Test-only extensions loaded by composer package name.                  |
| [Site based tests](testing/site-based-tests.md)           | Site configuration, languages, frontend sub-requests.                  |
| [Environment state](testing/environment-state.md)         | Application type and language context in functional tests.             |

## [Workflow](workflow/Index.md)

| Page                                                                   | Contents                                                |
|------------------------------------------------------------------------|---------------------------------------------------------|
| [Commit messages](workflow/commit-messages.md)                         | TYPO3 core commit message conventions.                  |
| [Pull requests](workflow/pull-requests.md)                             | Branching, the pre-flight checklist, review.            |
| [Changelog and documentation](workflow/changelog-and-documentation.md) | reST changelog entries, rendering, the core changelogs. |
| [Releasing](workflow/releasing.md)                                     | `setVersion.sh` and `release.sh`.                       |

## Conventions of this documentation

- Every directory has an `Index.md` linking its pages; every page ends with a
  *See also* section.
- Pages document **why**, not just **what** — the reasoning is the part that does
  not survive in code.
- A change updates the page covering it in the same commit.
- **Tables are always formatted.** Every cell is padded so the pipes line up, and
  the separator row is as wide as the widest cell in its column:

  ```markdown
  <!-- no -->
  | Header 1 | Header 2 |
  |----------|----------|
  | Value 1 with long text | Value 2 |

  <!-- yes -->
  | Header 1               | Header 2 |
  |------------------------|----------|
  | Value 1 with long text | Value 2  |
  ```

  Both render identically, which is exactly the problem: an unaligned table is
  invisible until someone edits it, and then the reflow touches every row and
  buries the actual change in the diff. Alignment markers (`:---`, `---:`,
  `:---:`) are kept and padded the same way.
