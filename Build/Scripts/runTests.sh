#!/usr/bin/env bash

# ----------------------------------------------------------------------------------------------------------------------
# sbuerk/modern-extbase-frontend-edit test runner based on docker/podman.
# Adopted from TYPO3 Core Development and extension based additions.
# ----------------------------------------------------------------------------------------------------------------------
if [ "${CI}" != "true" ]; then
    trap 'echo "runTests.sh SIGINT signal emitted";cleanUp;exit 2' SIGINT
fi

printSummary() {
    cleanUp

    # Print summary
    echo "" >&2
    echo "###########################################################################" >&2
    echo "Result of ${TEST_SUITE}" >&2
    echo "Container runtime: ${CONTAINER_BIN}" >&2
    echo "Container suffix: ${SUFFIX}"
    if [[ ${IS_CORE_CI} -eq 1 ]]; then
        echo "Environment: CI" >&2
    else
        echo "Environment: local" >&2
    fi
    echo "PHP: ${PHP_VERSION}" >&2
    echo "TYPO3: ${CORE_VERSION}" >&2
    if [[ ${TEST_SUITE} =~ ^(functional|acceptance)$ ]]; then
        case "${DBMS}" in
            mariadb|mysql|postgres)
                echo "DBMS: ${DBMS}  version ${DBMS_VERSION}  driver ${DATABASE_DRIVER}" >&2
                ;;
            sqlite)
                echo "DBMS: ${DBMS}" >&2
                ;;
        esac
    fi
    if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
        echo "SUCCESS" >&2
    else
        echo "FAILURE" >&2
    fi
    echo "###########################################################################" >&2
    echo "" >&2

    # Exit with code of test suite - This script return non-zero if the executed test failed.
    exit $SUITE_EXIT_CODE
}

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 10 ]; then
              echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

waitForDatabase() {
    # An open TCP port is not a ready database, so this asks the server to
    # answer a query rather than probing the port with waitFor().
    #
    # The probe runs the vendor's own client, from the database image itself,
    # because that client is the only thing guaranteed to speak the protocol
    # of the version under test - and because it needs no extension to be
    # compiled into the PHP image.
    #
    # The budget is a minute rather than the ten seconds waitFor() allows. A
    # database initialising its data directory for the first time on a loaded
    # machine takes longer than that, and the price of waiting too long is a
    # slower run, while the price of waiting too briefly is a suite that fails
    # for a reason that has nothing to do with the code under test.
    local KIND=${1}
    local HOST=${2}
    local IMAGE=${3}
    local PROBE=""
    case ${KIND} in
        mariadb|mysql)
            # MYSQL_PWD rather than -p, which warns about the password on the
            # command line and writes that warning into every probe iteration.
            PROBE="MYSQL_PWD=funcp mysql -h ${HOST} -u root -e 'SELECT 1' >/dev/null 2>&1"
            ;;
        postgres)
            PROBE="PGPASSWORD=funcp psql -h ${HOST} -U funcu -d funcu -c 'SELECT 1' >/dev/null 2>&1"
            ;;
        *)
            echo "waitForDatabase() does not know the DBMS \"${KIND}\"." >&2
            kill -SIGINT -$$
            ;;
    esac
    local TESTCOMMAND="
        COUNT=0;
        until ${PROBE}; do
            if [ \"\${COUNT}\" -gt 60 ]; then
              echo \"The ${KIND} server \\\"${HOST}\\\" did not answer a query within 60 seconds. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${IMAGE} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

reportDatabaseUnavailable() {
    # Called when a functional run failed. A database that stops during the run
    # produces one connection error per remaining test and no statement of what
    # actually happened, so a reader is left to infer it from several hundred
    # stack traces. Say it instead, and show what the server said last.
    #
    # This can only report anything because the database container is started
    # without "--rm": an exited container that removes itself takes its log
    # with it. cleanUp() removes it either way, since it enumerates everything
    # attached to the network.
    local NAME=${1}
    if [[ -n "$(${CONTAINER_BIN} ps --filter name=^${NAME}$ --format '{{.Names}}' 2>/dev/null)" ]]; then
        return
    fi

    echo "" >&2
    echo "The database container \"${NAME}\" is not running any more." >&2
    echo "The failures above are therefore very likely a consequence of that," >&2
    echo "not of the code under test. Its last output was:" >&2
    echo "" >&2
    ${CONTAINER_BIN} logs --tail 50 "${NAME}" >&2 2>&1 || echo "  (the container is gone, so it kept no log)" >&2
    echo "" >&2
}

startAcceptanceInstance() {
    # Brings up everything the acceptance suite needs, in the order it needs it:
    # the seeded TYPO3 instance, a php-fpm pool serving it and an apache in front
    # of that. Both server containers are attached to ${NETWORK} under the aliases
    # "phpfpm" and "web", which is what lets the browser container reach the site
    # as "http://web/" - the same host the site configuration carries as its base,
    # and the reason no host port is published.
    #
    # Attaching them to the network is also what makes cleanUp() remove them: it
    # enumerates the network, not a list of names.
    local INSTANCE_PATH="${ROOT_DIR}/.Build/Web/typo3temp/var/tests/acceptance"

    rm -rf \
        "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/playwright-reports" \
        "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/playwright-results"

    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name acceptance-seed-${SUFFIX} \
        ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
        ${IMAGE_PHP} php -dxdebug.mode=off Build/Scripts/setupAcceptanceInstance.php
    SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary

    # "web" is both the network alias and the server name, because the site
    # configuration carries "http://web/" as its base and TYPO3 resolves a site
    # by the host it was requested with.
    APACHE_COMMON_OPTIONS="-e APACHE_RUN_SERVERNAME=web -e APACHE_RUN_DOCROOT=${INSTANCE_PATH} -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000"

    # Which user the two server processes run as is the whole game here, and the
    # two runtimes need opposite answers:
    #
    # - **docker** runs the container as root unless told otherwise, so both are
    #   pinned to the host uid/gid. That is the only way php-fpm can write the
    #   SQLite file of a bind mount owned by the host user.
    # - **rootless podman** already maps the container root to the host user, so
    #   root *is* the right answer and "--user ${HOST_UID}" is the wrong one - it
    #   lands on a subordinate uid with no write access, and every request then
    #   fails with "attempt to write a readonly database" while apache still
    #   serves a perfectly convincing TYPO3 exception page.
    #
    # Core passes "${USERSET}" in both branches and gets away with it because its
    # CI runs docker. This is deliberately not copied.
    #
    # "#${HOST_GID}" rather than the "#${HOST_PID}" core passes: the value is a
    # group id, and core's is a long standing typo that is harmless only because
    # both of its containers make it.
    if [ "${CONTAINER_BIN}" == "docker" ]; then
        APACHE_OPTIONS="-e APACHE_RUN_USER=#${HOST_UID} -e APACHE_RUN_GROUP=#${HOST_GID}"
        ${CONTAINER_BIN} run --rm -d --name acceptance-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm \
            --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} \
            -e PHPFPM_USER=${HOST_UID} -e PHPFPM_GROUP=${HOST_GID} \
            -v ${ROOT_DIR}:${ROOT_DIR} ${IMAGE_PHP} php-fpm -d xdebug.mode=off >/dev/null
        SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
        ${CONTAINER_BIN} run --rm -d --name acceptance-web-${SUFFIX} --network ${NETWORK} --network-alias web \
            --add-host ${CONTAINER_HOST}:host-gateway \
            -v ${ROOT_DIR}:${ROOT_DIR} ${APACHE_OPTIONS} ${APACHE_COMMON_OPTIONS} ${IMAGE_APACHE} >/dev/null
        SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
    else
        APACHE_OPTIONS="-e APACHE_RUN_USER=#0 -e APACHE_RUN_GROUP=#0"
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name acceptance-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm \
            -e PHPFPM_USER=0 -e PHPFPM_GROUP=0 \
            -v ${ROOT_DIR}:${ROOT_DIR}:Z ${IMAGE_PHP} php-fpm -R -d xdebug.mode=off >/dev/null
        SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name acceptance-web-${SUFFIX} --network ${NETWORK} --network-alias web \
            -v ${ROOT_DIR}:${ROOT_DIR}:Z ${APACHE_OPTIONS} ${APACHE_COMMON_OPTIONS} ${IMAGE_APACHE} >/dev/null
        SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
    fi

    waitFor web 80
}

cleanUp() {
    # "-a", because a container that already exited is still attached and still
    # has to go. The database containers are deliberately started without
    # "--rm" so that reportDatabaseUnavailable() can still read the log of one
    # that died during a run, which makes an exited container a normal state
    # here rather than an exceptional one.
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps -a --filter network=${NETWORK} --format='{{.Names}}')
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} rm -f ${ATTACHED_CONTAINER} >/dev/null
    done
    ${CONTAINER_BIN} network rm -f ${NETWORK} >/dev/null
}

handleDbmsOptions() {
    # -a, -d, -i depend on each other. Validate input combinations and set defaults.
    case ${DBMS} in
        mariadb)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.4"
            if ! [[ ${DBMS_VERSION} =~ ^(10.4|10.5|10.6|10.7|10.8|10.9|10.10|10.11|11.0|11.1|11.2|11.3|11.4|11.5|11.6|11.7|11.8)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        mysql)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.0"
            if ! [[ ${DBMS_VERSION} =~ ^(8.0|8.1|8.2|8.3|8.4)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        postgres)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10"
            if ! [[ ${DBMS_VERSION} =~ ^(10|11|12|13|14|15|16|17|18)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        sqlite)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            if [ -n "${DBMS_VERSION}" ]; then
                echo "Invalid combination -d ${DBMS} -i ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            echo >&2
            echo "Use \"Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
            exit 1
            ;;
    esac
}

cleanCacheFiles() {
    echo -n "Clean caches ... "
    rm -rf \
        .cache \
        .php-cs-fixer.cache
    echo "done"
}

cleanJsFiles() {
    # Intermediates of the frontend asset build only. The compiled artifacts in
    # "Resources/Public/" are committed files and are never removed here - use
    # "checkJsBuildClean", which deletes and rebuilds them on purpose.
    echo -n "Clean frontend build related files ... "
    rm -rf \
        Build/node_modules \
        Build/.cache
    echo "done"
}

cleanTestFiles() {
    # test related
    echo -n "Clean test related files ... "
    rm -rf \
        .Build/Web/typo3temp/var/tests/
    echo "done"
}

cleanRenderedDocumentationFiles() {
    echo -n "Clean rendered documentation files ... "
    rm -rf \
        Documentation-GENERATED-temp
    echo "done"
}

loadHelp() {
    # Load help text into $HELP
    read -r -d '' HELP <<EOF
sbuerk/modern-extbase-frontend-edit test runner. Execute unit, functional and other test suites
in a container based test environment. Handles execution of single test files,
sending xdebug information to a local IDE and more.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies which test suite to run
            - acceptance: browser based acceptance tests, driven by Playwright
            - buildJs: compile Build/Sources/ into Resources/Public/
            - cgl: test and fix all php files
            - checkBom: check UTF-8 files do not contain BOM
            - checkDocumentationScreenshots: check the committed documentation screenshots still
              match the surface, and that every one of them is produced and embedded
            - checkExceptionCodes: check for duplicate and missing exception codes
            - checkJsBuildClean: check the committed Resources/Public/ artifacts match Build/Sources/
            - checkMarkdownTables: check markdown tables are formatted, "-- --fix" to format them
            - checkRstSectionAdornments: check reST adornments match their title, "-- --fix" to adjust them
            - checkTestMethodsPrefix: check test methods do not start with "test"
            - clean: clean up build, cache, rendered documentation and testing related files
            - cleanCache: clean up cache related files and folders
            - cleanJs: clean up frontend build related files and folders (Build/node_modules)
            - cleanRenderedDocumentation: clean up rendered documentation (Documentation-GENERATED-temp)
            - cleanTests: clean up test related files and folders
            - composer: "composer" with all remaining arguments dispatched
            - composerInstall: "composer install"
            - composerUpdate: "composer update", handy if host has no PHP
            - composerValidate: "composer validate --strict" of the root composer.json
            - functional: PHP functional tests
            - lintPhp: PHP linting
            - lintTypescript: eslint over every TypeScript tree, fixes by default, "-n" to only check
            - npm: "npm" with all remaining arguments dispatched, run in Build/
            - phpstan: phpstan analyze
            - phpstanGenerateBaseline: regenerate phpstan baseline, handy after phpstan updates
            - renderDocumentation: render the extension documentation into Documentation-GENERATED-temp
            - screenshotDocumentation: regenerate the documentation screenshots (writes into
              Documentation/), checked by checkDocumentationScreenshots
            - setVersion: apply a version across the repository, "-- <version> <type>"
            - typecheckJs: "tsc --noEmit" over every TypeScript tree, which the build does not do
            - unit (default): PHP unit tests
            - unitJs: TypeScript unit tests over Build/Sources/, run with "node --test"
            - unitRandom: PHP unit tests in random order, "-o <number>" to use a specific seed
            - visualRegression: compare the surface against the committed baselines,
              "-- --update-snapshots" to re-record them after an intended change
            - watchDocumentation: render the documentation and re-render it on every change,
              served on port 1337, a different port as first argument

        The six frontend suites - buildJs, checkJsBuildClean, lintTypescript, npm,
        typecheckJs and unitJs - run in a node container and are the only ones that are
        core version independent: they inspect Build/Sources/, Build/Tests/,
        Build/playwright/, Tests/Acceptance/ and Resources/Public/ and never the
        installed core, so "-t" does not change what they do. lintTypescript and
        typecheckJs cover every one of those trees; the other four are about the shipped
        assets and stop at Build/Sources/. They also need no composerUpdate, which makes
        them the only suites that are safe to run while the other core version's
        dependency set is installed.

    -b <docker|podman>
        Container environment:
            - docker
            - podman

        If not specified, podman will be used if available. Otherwise, docker is used.

    -a <mysqli|pdo_mysql>
        Only with -s functional
        Specifies to use another driver, following combinations are available:
            - mysql
                - mysqli (default)
                - pdo_mysql
            - mariadb
                - mysqli (default)
                - pdo_mysql

    -d <sqlite|mariadb|mysql|postgres>
        Only with -s functional. The acceptance suite is sqlite only.
        Specifies on which DBMS tests are performed
            - sqlite: (default): use sqlite
            - mariadb: use mariadb
            - mysql: use MySQL
            - postgres: use postgres

    -i version
        Specify a specific database version
        With "-d mariadb":
            - 10.4   short-term, maintained until 2024-06-18 (default)
            - 10.5   short-term, maintained until 2025-06-24
            - 10.6   long-term, maintained until 2026-06
            - 10.7   short-term, no longer maintained
            - 10.8   short-term, maintained until 2023-05
            - 10.9   short-term, maintained until 2023-08
            - 10.10  short-term, maintained until 2023-11
            - 10.11  long-term, maintained until 2028-02
            - 11.0   development series
            - 11.1   short-term development series
            - 11.2   short-term development series, maintained until 2024-11
            - 11.3   short-term development series, rolling release
            - 11.4   long-term, maintained until 2029-05
            - 11.5   short-term development series, maintained until 2024-11
            - 11.6   short-term development series, maintained until 2025-02
            - 11.7   short-term development series, maintained until 2025-05
            - 11.8   long-term, maintained until 2030-06
        With "-d mysql":
            - 8.0   maintained until 2026-04 (default) LTS
            - 8.1   unmaintained since 2023-10
            - 8.2   unmaintained since 2024-01
            - 8.3   maintained until 2024-04
            - 8.4   maintained until 2032-04 LTS
        With "-d postgres":
            - 10    unmaintained since 2022-11-10 (default)
            - 11    maintained until 2023-11-09
            - 12    maintained until 2024-11-14
            - 13    maintained until 2025-11-13
            - 14    maintained until 2026-11-12
            - 15    maintained until 2027-11-11
            - 16    maintained until 2028-11-09
            - 17    maintained until 2029-11-08
            - 18    maintained until 2030-11-14

    -t <13|14>
        Specifies the TYPO3 CORE Version to be used
            - 13: (default) use TYPO3 v13
            - 14: use TYPO3 v14
        Note that the dependencies must be installed for the selected core
        version first, which is done by the composerUpdate suite:
            ./Build/Scripts/runTests.sh -t 13 -s composerUpdate
        Gates executed with a different core version installed than selected
        report false positives.

    -p <8.2|8.3|8.4|8.5>
        Specifies the PHP minor version to be used
            - 8.2: use PHP 8.2 (default)
            - 8.3: use PHP 8.3
            - 8.4: use PHP 8.4
            - 8.5: use PHP 8.5

    -x
        Only with -s functional|unit|unitRandom
        Send information to host instance for test or system under test break points. This is especially
        useful if a local PhpStorm instance is listening on default xdebug port 9003. A different port
        can be selected with -y

    -y <port>
        Send xdebug information to a different port than default 9003 if an IDE like PhpStorm
        is not listening on default port.

    -o <number>
        Only with -s unitRandom
        Set specific random seed to replay a random run in this order again. The phpunit randomizer
        outputs the used seed at the end. Use that number to replay the unit tests in that order.

    -n
        Only with -s cgl|lintTypescript
        Activate dry-run in CGL check that does not actively change files and only prints broken ones.
        The same for the eslint run of lintTypescript, which fixes in place without it.

    -u
        Update existing typo3/core-testing-*:latest container images and remove dangling local volumes.
        New images are published once in a while and only the latest ones are supported by core testing.
        Use this if weird test errors occur. Also removes obsolete image versions of typo3/core-testing-*.

    -h
        Show this help.

Examples:
    # Install dependencies for TYPO3 v13 on PHP 8.2 (default matrix)
    ./Build/Scripts/runTests.sh -t 13 -p 8.2 -s composerUpdate

    # Run all unit tests using PHP 8.2
    ./Build/Scripts/runTests.sh -s unit
    ./Build/Scripts/runTests.sh -s unit -p 8.2

    # Run all unit tests and enable xdebug (have a PhpStorm listening on port 9003!)
    ./Build/Scripts/runTests.sh -s unit -x

    # Run a single functional test class on sqlite, phpunit arguments after "--"
    ./Build/Scripts/runTests.sh -s functional -d sqlite -- --filter ExtensionLoadedTest

    # Run the browser based acceptance suite, playwright arguments after "--"
    ./Build/Scripts/runTests.sh -s acceptance
    ./Build/Scripts/runTests.sh -s acceptance -- --grep "cancel"

    # Re-record the visual baselines after an intended styling change, then read
    # the diff before committing it.
    ./Build/Scripts/runTests.sh -s visualRegression -- --update-snapshots

    # After a styling change, the manual is stale too. The gate says which shots,
    # the generator rewrites them, and both take the same "--grep".
    ./Build/Scripts/runTests.sh -s checkDocumentationScreenshots
    ./Build/Scripts/runTests.sh -s screenshotDocumentation

    # Run functional tests on postgres 10
    ./Build/Scripts/runTests.sh -s functional -d postgres -i 10

    # Check the coding guidelines without changing files, as CI does
    ./Build/Scripts/runTests.sh -s cgl -n

    # Compile the frontend assets after a change below Build/Sources/
    ./Build/Scripts/runTests.sh -s buildJs

    # Prove the committed artifacts still match their sources, as CI does
    ./Build/Scripts/runTests.sh -s checkJsBuildClean

    # Add or update a node dependency, arguments after "--"
    ./Build/Scripts/runTests.sh -s npm -- install --save-dev lit@latest

    # Write documentation with a browser preview reloading on every save
    ./Build/Scripts/runTests.sh -s watchDocumentation
    ./Build/Scripts/runTests.sh -s watchDocumentation 4711

    # Apply a version across the repository, without needing PHP on the host
    ./Build/Scripts/runTests.sh -s setVersion -- 1.2.0 release --dry-run
EOF
}

# Test if docker exists, else exit out with error
if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script relies on docker or podman. Please install" >&2
    exit 1
fi

# Option defaults
TEST_SUITE="help"
CORE_VERSION="13"
DBMS="sqlite"
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
PHPUNIT_RANDOM=""
CGLCHECK_DRY_RUN=0
DATABASE_DRIVER=""
DBMS_VERSION=""
CONTAINER_BIN=""
CONTAINER_HOST="host.docker.internal"
DOCUMENTATION_PORT="1337"

# Option parsing updates above default vars
# Reset in case getopts has been used previously in the shell
OPTIND=1
# Array for invalid options
INVALID_OPTIONS=()
# Simple option parsing based on getopts (! not getopt)
while getopts "a:b:s:d:i:p:t:xy:o:nhu" OPT; do
    case ${OPT} in
        s)
            TEST_SUITE=${OPTARG}
            ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        a)
            DATABASE_DRIVER=${OPTARG}
            ;;
        d)
            DBMS=${OPTARG}
            ;;
        i)
            DBMS_VERSION=${OPTARG}
            ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.2|8.3|8.4|8.5)$ ]]; then
                INVALID_OPTIONS+=("p ${OPTARG}")
            fi
            ;;
        t)
            CORE_VERSION=${OPTARG}
            if ! [[ ${CORE_VERSION} =~ ^(13|14)$ ]]; then
                INVALID_OPTIONS+=("t ${OPTARG}")
            fi
            ;;
        x)
            PHP_XDEBUG_ON=1
            ;;
        y)
            PHP_XDEBUG_PORT=${OPTARG}
            ;;
        o)
            PHPUNIT_RANDOM="--random-order-seed=${OPTARG}"
            ;;
        n)
            CGLCHECK_DRY_RUN=1
            ;;
        h)
            loadHelp
            echo "${HELP}"
            exit 0
            ;;
        u)
            TEST_SUITE=update
            ;;
        \?)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
        :)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
    esac
done

# Exit on invalid options
if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s):" >&2
    for I in "${INVALID_OPTIONS[@]}"; do
        echo "-"${I} >&2
    done
    echo >&2
    echo "call \"Build/Scripts/runTests.sh -h\" to display help and valid options"
    exit 1
fi

handleDbmsOptions

COMPOSER_ROOT_VERSION="1.0.0-dev"
CONTAINER_INTERACTIVE="-it --init"
HOST_UID=$(id -u)
HOST_GID=$(id -g)
# Additional container arguments a caller may inject, for instance a CI runner that has to pass
# "--userns" or a network mode. Declared so the expansions below are defined without a caller.
CI_PARAMS="${CI_PARAMS:-}"
USERSET=""
if [ $(uname) != "Darwin" ]; then
    USERSET="--user $HOST_UID"
fi

# Go to the directory this script is located, so everything else is relative
# to this dir, no matter from where this script is called, then go up two dirs.
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
ROOT_DIR="${PWD}"

# Create .cache dir: composer need this.
mkdir -p .cache/composer
mkdir -p .Build/Web/typo3temp/var/tests

IS_CORE_CI=0
if [ "${CI}" == "true" ]; then
    # ENV var "CI" is set by the pipeline. We use it here to distinct 'local' and 'CI' environment.
    IS_CORE_CI=1
    CONTAINER_INTERACTIVE=""
elif [ ! -t 0 ] || [ ! -t 1 ]; then
    # If stdin or stdout is not a TTY (a wrapper script, a pipe, an IDE run configuration or any
    # other non-interactive shell), drop the interactive "-it" flags to avoid the podman warning
    # "The input device is not a TTY.", the corresponding docker failure, and TTY control
    # characters in redirected output. "--init" is kept so the PID 1 init process still forwards
    # signals such as ctrl-c to the test process.
    CONTAINER_INTERACTIVE="--init"
fi

# determine default container binary to use: 1. podman 2. docker
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_DOCS="ghcr.io/typo3-documentation/render-guides:latest"
# The image TYPO3 core itself uses for its JavaScript suites on 13.4, 14.3 and main.
# It carries node 24 and npm 11, which match the "engines" range of Build/package.json,
# and it ships git, which "checkJsBuildClean" needs. Pinned rather than ":latest", the
# way core pins it - a node major changing under a committed build artifact is exactly
# the kind of surprise that gate exists to catch, not to produce.
IMAGE_NODEJS="ghcr.io/typo3/core-testing-nodejs24:1.1"
# The two images of the acceptance suite, both pinned to the versions TYPO3 core
# pins for its own Playwright suite. The Playwright image tag and the
# "@playwright/test" entry of Build/playwright/package.json carry the same
# version on purpose: the image ships the browser binaries, the package ships the
# runner that drives them, and a runner newer than its browsers is a class of
# failure that reads like a broken test.
IMAGE_APACHE="ghcr.io/typo3/core-testing-apache24:1.7"
IMAGE_PLAYWRIGHT="mcr.microsoft.com/playwright:v1.56.1-noble"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
# PostgreSQL 18 moved "PGDATA" from "/var/lib/postgresql/data" to
# "/var/lib/postgresql/<major>/docker" and refuses to start when a mount point sits at the old
# location. Mounting one level above at "/var/lib/postgresql" is the documented recommendation
# for that case, while earlier versions expect the mount at the data directory itself.
POSTGRES_TMPFS_MOUNT="/var/lib/postgresql/data"
if [ "${DBMS}" = "postgres" ] && [ "${DBMS_VERSION}" -ge 18 ]; then
    POSTGRES_TMPFS_MOUNT="/var/lib/postgresql"
fi

# Set $1 to first mass argument, this is the optional test file or test directory to execute
shift $((OPTIND - 1))

SUFFIX=$(echo $RANDOM)
NETWORK="modern-extbase-frontend-edit-${SUFFIX}"
${CONTAINER_BIN} network create ${NETWORK} >/dev/null

if [ "${CONTAINER_BIN}" == "docker" ]; then
    # docker needs the add-host for xdebug remote debugging. podman has host.container.internal built in
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    CONTAINER_SIMPLE_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    DOCUMENTATION_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm ${USERSET} -v ${ROOT_DIR}:/project"
    # docker creates the tmpfs owned by root, which the container user - "--user" above - may not
    # be able to write to, and SQLite then fails with "unable to open database file". podman maps
    # the container user to the host user and needs no ownership here.
    #
    # Ownership and mode are both set, because they fail in different environments. A probe inside
    # a container on a GitHub hosted runner showed the mount as "root:root" mode 0755 with the
    # container user at "uid=1001 gid=0" - the group is 0 because "--user" above passes no group -
    # so neither the owner nor the group bits applied. Locally the same mount comes up 0775, which
    # is why setting the owner alone was enough there and not on the runner.
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid,uid=${HOST_UID},gid=${HOST_GID},mode=1777"
else
    # podman
    CONTAINER_HOST="host.containers.internal"
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid"
    if [ $( uname ) = "Linux" ]; then
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR}:Z -w ${ROOT_DIR}"
        CONTAINER_SIMPLE_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm -v ${ROOT_DIR}:${ROOT_DIR}:Z -w ${ROOT_DIR}"
        DOCUMENTATION_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm -v ${ROOT_DIR}:${ROOT_DIR}:Z -v ${ROOT_DIR}:/project"
    else
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
        CONTAINER_SIMPLE_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
        DOCUMENTATION_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm -v ${ROOT_DIR}:${ROOT_DIR} -v ${ROOT_DIR}:/project"
    fi
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
fi

# Suite execution
case ${TEST_SUITE} in
    acceptance)
        # The only suite that needs a running TYPO3 and a real browser.
        #
        # SQLite only, and that is a decision rather than a gap: the reset
        # between specs is a file copy of a snapshot, which is what makes an
        # assertion about persistence possible without a per test container
        # start. A server based DBMS would need a different mechanism, and no
        # spec here asserts anything a second platform could disagree about -
        # the queries are covered by "-s functional -d mariadb|mysql|postgres".
        if [ "${DBMS}" != "sqlite" ]; then
            echo "The acceptance suite supports \"-d sqlite\" only." >&2
            SUITE_EXIT_CODE=1
        else
            startAcceptanceInstance
            # "npm ci" runs in the Playwright image itself rather than in the
            # node image the asset suites use. It only installs the runner - the
            # browsers are already in the image, which is what the skip variable
            # says - so a second image and a second container buy nothing here.
            #
            # Arguments are handed to "sh -c" as positional parameters instead of
            # being interpolated, so a "--grep" pattern containing spaces stays
            # one argument.
            COMMAND=(/bin/sh -c 'cd Build/playwright && npm ci --no-audit --no-fund && npm test -- "$@"' acceptance "$@")
            # NODE_PATH is what lets a spec in "Tests/Acceptance/" import
            # "@playwright/test": node resolves "node_modules" by walking up from
            # the importing file, and the specs deliberately do not live next to
            # the manifest that installs the runner. The alternative is a
            # "node_modules" in the repository root, i.e. a third package.json.
            #
            # "node:sqlite" - which the reset between specs verifies itself with
            # - is experimental in node 22 and prints a warning per worker
            # process. The suite fails on nothing else that is written to stderr,
            # so the warning is silenced rather than tolerated as noise.
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name acceptance-${SUFFIX} \
                -e HOME=${ROOT_DIR}/.cache \
                -e NODE_PATH=${ROOT_DIR}/Build/playwright/node_modules \
                -e NODE_OPTIONS=--disable-warning=ExperimentalWarning \
                -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
                -e CHROME_SANDBOX=false \
                ${IMAGE_PLAYWRIGHT} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        ;;
    screenshotDocumentation)
        # Regenerates the screenshots the rendered documentation embeds, by
        # driving the same seeded instance the acceptance suite drives.
        #
        # This is **not a gate**: it writes into the tracked tree, which no gate
        # does, and it is deliberately absent from the CI workflow. What verifies
        # its output is the sibling suite "checkDocumentationScreenshots", which
        # takes the same shots and compares them instead of writing them. Before
        # that suite existed, nothing checked these images at all, and three of
        # the six had been photographed mid-transition for eight pull requests
        # without anyone noticing.
        #
        # Generation is containerised with no host escape hatch on purpose. The
        # fonts come from the Playwright image, so a shot taken on a host would
        # differ from every other one in the manual.
        if [ "${DBMS}" != "sqlite" ]; then
            echo "The screenshot generator supports \"-d sqlite\" only." >&2
            SUITE_EXIT_CODE=1
        else
            startAcceptanceInstance
            COMMAND=(/bin/sh -c 'cd Build/playwright && npm ci --no-audit --no-fund && npm run screenshots -- "$@"' screenshots "$@")
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name screenshot-documentation-${SUFFIX} \
                -e HOME=${ROOT_DIR}/.cache \
                -e NODE_PATH=${ROOT_DIR}/Build/playwright/node_modules \
                -e NODE_OPTIONS=--disable-warning=ExperimentalWarning \
                -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
                -e CHROME_SANDBOX=false \
                ${IMAGE_PLAYWRIGHT} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        ;;
    checkDocumentationScreenshots)
        # The gate over "-s screenshotDocumentation". Takes every configured shot
        # against the same seeded instance and compares it with the image the
        # repository carries, instead of overwriting it.
        #
        # One environment variable is the whole difference, and that is the
        # design: a check that reached the surface by its own route would be
        # checking that route. The login, the reset, the viewport, the scale
        # factor, the preparation steps, the clip, the screenshot options and the
        # encoder settings are the generator's, not a copy of them.
        #
        # It also answers the three questions a pixel comparison cannot: whether
        # every image is produced by a configured shot, whether every image is
        # embedded by a chapter, and whether every embed resolves. The renderer
        # only warns about the last one and still exits zero, so
        # "-s renderDocumentation" is not a second opinion.
        #
        # Nothing is written into "Documentation/" here. On failure the three
        # images a person needs - committed, taken, diff - go to the test
        # artifact directory, and the failure message names it.
        if [ "${DBMS}" != "sqlite" ]; then
            echo "The documentation screenshot check supports \"-d sqlite\" only." >&2
            SUITE_EXIT_CODE=1
        else
            # "startAcceptanceInstance" clears the acceptance report directories
            # and only those, so this one has to be cleared here - a diff left
            # over from the previous run is the worst possible thing to hand
            # somebody who is looking at why the gate just went red.
            rm -rf "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/screenshot-check-reports"
            startAcceptanceInstance
            COMMAND=(/bin/sh -c 'cd Build/playwright && npm ci --no-audit --no-fund && npm run screenshots -- "$@"' screenshots "$@")
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-documentation-screenshots-${SUFFIX} \
                -e HOME=${ROOT_DIR}/.cache \
                -e NODE_PATH=${ROOT_DIR}/Build/playwright/node_modules \
                -e NODE_OPTIONS=--disable-warning=ExperimentalWarning \
                -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
                -e CHROME_SANDBOX=false \
                -e DOCUMENTATION_SCREENSHOTS=check \
                ${IMAGE_PLAYWRIGHT} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        ;;
    visualRegression)
        # Compares the surface against committed baseline images, one component
        # per baseline.
        #
        # This **is** a gate, unlike "-s screenshotDocumentation", and the two are
        # otherwise easy to confuse: this one asserts and fails, that one writes
        # into the tracked tree and cannot fail. It runs in CI for the same
        # reason - a visual suite nobody runs is decoration.
        #
        # Containerised with no host escape hatch, and here the reason is the
        # whole mechanism rather than a nicety: the fonts come from the Playwright
        # image, so a baseline recorded on a host disagrees with every machine
        # that did not record it.
        #
        # Re-record after an intended change, and look at the diff before you do:
        #   Build/Scripts/runTests.sh -s visualRegression -- --update-snapshots
        if [ "${DBMS}" != "sqlite" ]; then
            echo "The visual regression suite supports \"-d sqlite\" only." >&2
            SUITE_EXIT_CODE=1
        else
            startAcceptanceInstance
            COMMAND=(/bin/sh -c 'cd Build/playwright && npm ci --no-audit --no-fund && npm run visual -- "$@"' visual "$@")
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name visual-regression-${SUFFIX} \
                -e HOME=${ROOT_DIR}/.cache \
                -e NODE_PATH=${ROOT_DIR}/Build/playwright/node_modules \
                -e NODE_OPTIONS=--disable-warning=ExperimentalWarning \
                -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
                -e CHROME_SANDBOX=false \
                ${IMAGE_PLAYWRIGHT} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        ;;
    buildJs)
        COMMAND="cd Build && npm ci && npm run build"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name build-js-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        # Active dry-run for cgl needs not "-n" but specific options
        CSFIXER_DRYRUN=""
        if [ "${CGLCHECK_DRY_RUN}" -eq 1 ]; then
            CSFIXER_DRYRUN="--dry-run --diff"
        fi
        COMMAND="php -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v ${CSFIXER_DRYRUN} --config=Build/php-cs-fixer/config.php"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} ${COMMAND}
        SUITE_EXIT_CODE=$?
        ;;
    checkBom)
        COMMAND="Build/Scripts/checkUtf8Bom.sh"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-bom-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkExceptionCodes)
        COMMAND="Build/Scripts/duplicateExceptionCodeCheck.sh"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-exception-codes-${SUFFIX} ${IMAGE_PHP} /bin/bash -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkJsBuildClean)
        # The gate that makes committed build artifacts trustworthy. "Resources/Public/"
        # is tracked because neither composer nor a TER upload runs a node build, and an
        # artifact that no longer matches its source passes every review, ships to every
        # installation and is only noticed when someone wonders why a fix had no effect.
        #
        # The artifacts are deleted rather than overwritten, so a source file that stopped
        # producing an output is caught as well - "git status" then reports the deletion.
        # A green run leaves the working tree exactly as it found it; a red one leaves the
        # rebuilt files in place, which is what the diff below is showing.
        #
        # "safe.directory" is passed as environment rather than written to a config file:
        # in CI the container runs as root against a checkout owned by the runner user,
        # and git refuses to operate in a repository owned by someone else.
        COMMAND="export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'; \
            rm -rf Resources/Public/JavaScript Resources/Public/Css; \
            cd Build && npm ci && npm run build || exit 1; \
            cd ${ROOT_DIR} || exit 1; \
            CHANGED=\$(git status --porcelain --untracked-files=all -- Resources/Public); \
            if [ -n \"\${CHANGED}\" ]; then \
                echo ''; \
                echo 'The committed artifacts below Resources/Public/ do not match Build/Sources/:'; \
                echo \"\${CHANGED}\"; \
                echo ''; \
                git --no-pager diff -- Resources/Public; \
                echo ''; \
                echo 'Run \"Build/Scripts/runTests.sh -s buildJs\" and commit the result.'; \
                exit 1; \
            fi; \
            echo 'The committed artifacts below Resources/Public/ match Build/Sources/.'"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-js-build-clean-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkMarkdownTables)
        COMMAND="php -dxdebug.mode=off Build/Scripts/checkMarkdownTables.php $@"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-markdown-tables-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkRstSectionAdornments)
        COMMAND="php -dxdebug.mode=off Build/Scripts/checkRstSectionAdornments.php $@"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-rst-section-adornments-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkTestMethodsPrefix)
        COMMAND="php -dxdebug.mode=off Build/Scripts/testMethodPrefixChecker.php"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-test-methods-prefix-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        cleanCacheFiles
        cleanJsFiles
        cleanRenderedDocumentationFiles
        cleanTestFiles
        SUITE_EXIT_CODE=$?
        ;;
    cleanCache)
        cleanCacheFiles
        SUITE_EXIT_CODE=$?
        ;;
    cleanJs)
        cleanJsFiles
        SUITE_EXIT_CODE=$?
        ;;
    cleanRenderedDocumentation)
        cleanRenderedDocumentationFiles
        SUITE_EXIT_CODE=$?
        ;;
    cleanTests)
        cleanTestFiles
        SUITE_EXIT_CODE=$?
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_SIMPLE_PARAMS} --name composer-command-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerInstall)
        ${CONTAINER_BIN} run ${CONTAINER_SIMPLE_PARAMS} --name composer-install-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} composer install
        SUITE_EXIT_CODE=$?
        ;;
    composerValidate)
        ${CONTAINER_BIN} run ${CONTAINER_SIMPLE_PARAMS} --name composer-validate-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} composer validate --strict --no-check-lock
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        rm -rf .Build composer.lock composer.json.orig
        if [[ ${IS_CORE_CI} -eq 0 ]]; then
            # Locally the cache is dropped along with the dependency set, as it was while it still
            # lived below ".Build/". This is a precaution, not a fix for a reproduced defect:
            # switching between the core versions also switches the major version of
            # "typo3/class-alias-loader", a working copy accumulates months of such switches, and
            # an install resolving against a cache from the other major is a class of failure that
            # is tedious to recognize. One download of a dependency set that was about to be
            # replaced anyway is the cheaper side of that trade.
            #
            # In CI the trade goes the other way: every job starts from an empty checkout, installs
            # once and ends, so there is no earlier state to collide with, and the cache is restored
            # on purpose to avoid downloading the dependency set in every job.
            rm -rf .cache
            mkdir -p .cache/composer
        fi
        \cp -f composer.json composer.json.orig
        ${CONTAINER_BIN} run ${CONTAINER_SIMPLE_PARAMS} --name composer-require-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} composer require --dev --no-update "typo3/minimal":"^${CORE_VERSION}"
        SUITE_EXIT_CODE=$?
        if [[ "${SUITE_EXIT_CODE}" -eq 0 ]]; then
          ${CONTAINER_BIN} run ${CONTAINER_SIMPLE_PARAMS} --name composer-update-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} composer install
          SUITE_EXIT_CODE=$?
        fi
        [[ -f composer.json.orig ]] && \cp -f composer.json.orig composer.json
        ;;
    functional)
        PHPUNIT_CONFIG_FILE="Build/phpunit/FunctionalTests.xml"
        COMMAND=(.Build/bin/phpunit -c ${PHPUNIT_CONFIG_FILE} --exclude-group not-${DBMS} --exclude-group not-core-${CORE_VERSION} "$@")
        case ${DBMS} in
            mariadb)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
                waitForDatabase mariadb mariadb-func-${SUFFIX} ${IMAGE_MARIADB}
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && reportDatabaseUnavailable mariadb-func-${SUFFIX}
                ;;
            mysql)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
                waitForDatabase mysql mysql-func-${SUFFIX} ${IMAGE_MYSQL}
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && reportDatabaseUnavailable mysql-func-${SUFFIX}
                ;;
            postgres)
                ${CONTAINER_BIN} run ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs ${POSTGRES_TMPFS_MOUNT}:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
                waitForDatabase postgres postgres-func-${SUFFIX} ${IMAGE_POSTGRES}
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && reportDatabaseUnavailable postgres-func-${SUFFIX}
                ;;
            sqlite)
                # create sqlite tmpfs mount typo3temp/var/tests/functional-sqlite-dbs/ to avoid permission issues
                rm -rf "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
                SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
                mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
                SUITE_EXIT_CODE=$? && [[ "${SUITE_EXIT_CODE}" -ne 0 ]] && printSummary
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:${TMPFS_MOUNT_OPTIONS}"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    lintPhp)
        # "Build/node_modules" is excluded because npm packages ship PHP files of their
        # own - test fixtures and vendored tools - and linting them says nothing about
        # this repository while adding tens of thousands of files to the run.
        COMMAND="find . -name \\*.php ! -path "./.Build/\\*" ! -path "./.agent/\\*" ! -path "./.cache/\\*" ! -path "./Build/node_modules/\\*" -print0 | xargs -0 -n1 -P4 php -dxdebug.mode=off -l >/dev/null"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintTypescript)
        # Mirrors "cgl": it fixes in place, and only checks when "-n" is given.
        NPM_LINT_SCRIPT="lint:fix"
        if [ "${CGLCHECK_DRY_RUN}" -eq 1 ]; then
            NPM_LINT_SCRIPT="lint"
        fi
        COMMAND="cd Build && npm ci && npm run ${NPM_LINT_SCRIPT}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-typescript-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    npm)
        # Escape hatch, mirroring the "composer" suite:
        #   ./Build/Scripts/runTests.sh -s npm -- install --save-dev lit@latest
        # The working directory is overridden to Build/, where package.json lives.
        COMMAND=(npm "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} -w ${ROOT_DIR}/Build --name npm-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        PHPSTAN_CONFIG_FILE="Build/phpstan/Core${CORE_VERSION}/phpstan.neon"
        COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-interaction --memory-limit 4G "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstanGenerateBaseline)
        PHPSTAN_CONFIG_FILE="Build/phpstan/Core${CORE_VERSION}/phpstan.neon"
        COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-interaction --memory-limit 4G --allow-empty-baseline --generate-baseline=Build/phpstan/Core${CORE_VERSION}/phpstan-baseline.neon "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-baseline-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    renderDocumentation)
        cleanRenderedDocumentationFiles
        ${CONTAINER_BIN} run ${DOCUMENTATION_COMMON_PARAMS} --name render-documentation-${SUFFIX} ${IMAGE_DOCS} --no-progress --fail-on-error --config=Documentation Documentation
        SUITE_EXIT_CODE=$?
        ;;
    setVersion)
        # Arguments are the ones of the script itself, for instance:
        #   ./Build/Scripts/runTests.sh -s setVersion -- 1.2.0 release
        COMMAND=(Build/Scripts/setVersion.sh "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name set-version-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    typecheckJs)
        # Its own suite precisely because esbuild does not type check: without this the
        # build succeeds on TypeScript that does not compile.
        #
        # The acceptance manifest is installed as well, because the third project
        # checks the specs and those import "@playwright/test" - a type check of code
        # whose dependency is absent is not a type check. Only the runner is installed:
        # PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD keeps the browser binaries, which are a
        # several hundred megabyte download and which nothing here starts, out of this
        # suite. The acceptance suite itself sets the same variable and gets its
        # browsers from its image instead.
        COMMAND="cd Build && npm ci && npm --prefix playwright ci --no-audit --no-fund && npm run typecheck"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name typecheck-js-${SUFFIX} -e HOME=${ROOT_DIR}/.cache -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 ${IMAGE_NODEJS} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        PHPUNIT_CONFIG_FILE="Build/phpunit/UnitTests.xml"
        COMMAND=(.Build/bin/phpunit -c ${PHPUNIT_CONFIG_FILE} --exclude-group not-core-${CORE_VERSION} "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    unitJs)
        # The logic of the edit plugin that is not DOM shaped - payloads, the last
        # server known state, the edit sessions, the response interpretation and the
        # endpoint client - lives in modules of its own precisely so it can be covered
        # without a browser. "node --test" is therefore the whole runner: no jsdom, no
        # bundler, no second toolchain, and nothing in "package.json" that is not
        # already there.
        #
        # Arguments for the test runner go after "--", for instance:
        #   ./Build/Scripts/runTests.sh -s unitJs -- --test-name-pattern 'cancel'
        #
        # The arguments are handed to "sh -c" as positional parameters rather than
        # interpolated into the string, so a pattern containing spaces survives as one
        # argument. "unitJs" is the $0 the shell reports in an error message.
        COMMAND=(/bin/sh -c 'cd Build && npm ci && npm test -- "$@"' unitJs "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-js-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    unitRandom)
        PHPUNIT_CONFIG_FILE="Build/phpunit/UnitTests.xml"
        COMMAND=(.Build/bin/phpunit -c ${PHPUNIT_CONFIG_FILE} --exclude-group not-core-${CORE_VERSION} --order-by=random ${PHPUNIT_RANDOM} "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-random-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    watchDocumentation)
        # An optional first mass argument overrides the port, for a second instance or a taken one.
        DOCUMENTATION_PORT="${1:-${DOCUMENTATION_PORT}}"
        if ! [[ ${DOCUMENTATION_PORT} =~ ^[0-9]+$ ]]; then
            echo "Invalid port \"${DOCUMENTATION_PORT}\", expected a number." >&2
            SUITE_EXIT_CODE=1
        else
            cleanRenderedDocumentationFiles
            echo "Rendering Documentation/ and watching it for changes."
            echo "Open http://localhost:${DOCUMENTATION_PORT}/Index.html once the first render is done."
            echo "Press ctrl-c to stop."
            echo ""
            # Attached to the network so an interrupted run is caught by cleanUp(). Files added
            # while the server runs are not picked up; restart the suite for those.
            ${CONTAINER_BIN} run ${DOCUMENTATION_COMMON_PARAMS} --network ${NETWORK} --name watch-documentation-${SUFFIX} -p ${DOCUMENTATION_PORT}:${DOCUMENTATION_PORT} ${IMAGE_DOCS} --port ${DOCUMENTATION_PORT} --watch --config=Documentation Documentation
            SUITE_EXIT_CODE=$?
        fi
        ;;
    update)
        # pull typo3/core-testing-* versions of those ones that exist locally
        echo "> pull ghcr.io/typo3/core-testing-* versions of those ones that exist locally"
        ${CONTAINER_BIN} images "ghcr.io/typo3/core-testing-*" --format "{{.Repository}}:{{.Tag}}" | xargs -I {} ${CONTAINER_BIN} pull {}
        echo ""
        # remove "dangling" typo3/core-testing-* images (those tagged as <none>)
        echo "> remove \"dangling\" ghcr.io/typo3/core-testing-* images (those tagged as <none>)"
        ${CONTAINER_BIN} images --filter "reference=ghcr.io/typo3/core-testing-*" --filter "dangling=true" --format "{{.ID}}" | xargs -I {} ${CONTAINER_BIN} rmi -f {}
        echo ""
        SUITE_EXIT_CODE=0
        ;;
    help)
        loadHelp
        echo "${HELP}" >&2
        cleanUp
        exit 0
        ;;
    *)
        loadHelp
        echo "Invalid -s option argument ${TEST_SUITE}" >&2
        echo >&2
        echo "${HELP}" >&2
        cleanUp
        exit 1
        ;;
esac

# Cleanup, print summary && exit with exitcode
printSummary
