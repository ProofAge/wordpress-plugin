#!/usr/bin/env bash
# Copies the plugin as it must ship into <target-dir> (for example the svn trunk): everything except
# what .distignore lists. Fails if a development file is still there afterwards.
#
#   bin/build-release.sh <target-dir>
#
# It only copies files; it never runs svn.
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "usage: $0 <target-dir>" >&2
    exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$1"
target="$(cd "$1" && pwd)"

if [ "$target" = "$root" ]; then
    echo "target must not be the plugin directory" >&2
    exit 2
fi

# --delete would remove a checkout's .svn metadata, so keep it.
rsync -a --delete --exclude='.svn' --exclude-from="$root/.distignore" "$root"/ "$target"/

failed=0
for leftover in tests vendor bin composer.json composer.lock phpunit.xml.dist .phpunit.cache .distignore .gitignore .git .github README.md; do
    if [ -e "$target/$leftover" ]; then
        echo "development file in the release: $leftover" >&2
        failed=1
    fi
done

for required in proofage-age-verification.php readme.txt uninstall.php src; do
    if [ ! -e "$target/$required" ]; then
        echo "missing from the release: $required" >&2
        failed=1
    fi
done

exit "$failed"
