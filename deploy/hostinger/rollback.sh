#!/usr/bin/env bash
# Switch the live site back to the previous release. Database changes are not undone.
#   bash ~/domains/<domain>/pollproof/current/.deploy/rollback.sh ~/domains/<domain>/pollproof
set -euo pipefail
APP="$1"; PHP="${PHP_BIN:-php}"
NOW="$(readlink -f "$APP/current")"
PREV="$(ls -1dt "$APP"/releases/*/ | sed 's#/$##' | grep -v -x "$NOW" | head -1)"
[ -n "$PREV" ] || { echo "No earlier release to go back to."; exit 1; }
ln -sfn "releases/$(basename "$PREV")" "$APP/current.next"
mv -Tf "$APP/current.next" "$APP/current"
(cd "$APP/current" && "$PHP" artisan queue:restart)
echo "Live again: $(basename "$PREV")"
