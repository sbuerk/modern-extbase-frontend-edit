#!/usr/bin/env bash

#########################
#
# Check all UTF-8 files do not contain BOM.
#
# It expects to be run from the extension root.
#
##########################

# The pattern is "(with BOM)" rather than "UTF-8 Unicode (with BOM)". The longer
# form never matched: "file" 5.47 in the container images answers
# "Unicode text, UTF-8 (with BOM) text", so the words "UTF-8" and "Unicode"
# arrive in the opposite order and this gate reported success for every input,
# including a file that did carry a BOM. It is matched loosely on purpose — the
# wording around it has already changed once, and a gate that silently stops
# detecting anything is worse than one that occasionally names a UTF-16 file,
# which has no business in this repository either.
#
# "Build/node_modules" is excluded for two reasons: running "file" over tens of
# thousands of npm files takes minutes, and npm packages do ship BOM'd files, so
# the gate would report offenders in code this repository does not own.
FILES=`find . -type f \
    ! -path "./.Build/*" \
    ! -path "./.agent/*" \
    ! -path "./.cache/*" \
    ! -path "./.git/*" \
    ! -path "./.php-cs-fixer.cache" \
    ! -path "./Build/node_modules/*" \
    ! -path "./Documentation-GENERATED-temp/*" \
    -print0 | xargs -0 -n1 -P8 file {} | grep '(with BOM)'`

if [ -n "${FILES}" ]; then
    echo "Found UTF-8 files with BOM:";
    echo ${FILES};
    exit 1;
fi

exit 0
