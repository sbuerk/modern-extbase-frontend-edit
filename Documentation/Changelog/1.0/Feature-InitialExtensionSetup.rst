..  include:: /Includes.rst.txt

..  _feature-initial-modern-extbase-frontend-edit:

=================================
Feature: Initial extension set up
=================================

Description
===========

Initial set up of the ``sbuerk/modern-extbase-frontend-edit`` extension,
providing the project foundation the actual implementation is built on:

*   TYPO3 v13 and v14 support on PHP 8.2 up to 8.5, with core version aware
    implementations below :file:`Core13/` and :file:`Core14/`.
*   Dependency injection wiring through :file:`Configuration/Services.php`,
    with services configured by Symfony dependency injection attributes on the
    classes themselves.
*   Container based tooling through :file:`Build/Scripts/runTests.sh` covering
    linting, coding guidelines, static analysis, unit and functional tests and
    documentation rendering.
*   GitHub Actions workflows running these gates for TYPO3 v13 and v14 on pull
    requests.
*   A functional test setup ready to build on: strict PHPUnit configuration,
    fixture extensions loaded by their composer package name, site based tests
    issuing frontend sub-requests in several languages, and repository tests
    running in a built frontend environment.
*   Developer documentation below :file:`docs/`, covering the architecture,
    the quality gates, both test suites and the release workflow.
