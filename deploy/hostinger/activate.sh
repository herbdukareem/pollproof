#!/usr/bin/env bash
# Runs on the Hostinger server for every deploy (the GitHub workflow calls it).
# Turns an unpacked release into the live site with an atomic symlink switch, so a failed
# deploy never leaves the site half-updated.
#
#   activate.sh <app dir> <release name> <public_html dir>
#
# Layout under <app dir>:
#   releases/<name>/   one per deploy (the Laravel app from command/)
#   shared/.env        settings, kept between deploys
#   shared/storage/    uploads (EC8A photos, selfies), logs, sessions
#   current ->         the live release
# <public_html dir> is a symlink to current/public (or, with PUBLIC_MODE=copy, a copy of it).
set -euo pipefail

APP="$1"; REL="$2"; WEB="$3"
PHP="${PHP_BIN:-php}"
KEEP="${KEEP_RELEASES:-4}"
PUBLIC_MODE="${PUBLIC_MODE:-symlink}"
DIR="$APP/releases/$REL"

say() { printf '\n==> %s\n' "$*"; }

say "Checking PHP"
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "PHP 8.3 or newer is needed (found $("$PHP" -r 'echo PHP_VERSION;')). Set it in hPanel, or set PHP_BIN."; exit 1; }
for ext in pdo_mysql mbstring openssl fileinfo zip; do
  "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || { echo "The PHP extension '$ext' is not enabled. Turn it on in hPanel > Advanced > PHP Configuration."; exit 1; }
done
# Without sodium a slower pure-PHP copy (sodium_compat) signs and checks results; turn it on if you can.
"$PHP" -r "exit(extension_loaded('sodium') ? 0 : 1);" || echo "Note: the PHP extension 'sodium' is off, so signatures are checked in plain PHP (slower). Turn it on in hPanel > Advanced > PHP Configuration."


say "Shared storage and settings"
mkdir -p "$APP/shared/storage/app/private" "$APP/shared/storage/framework/cache/data" \
  "$APP/shared/storage/framework/sessions" "$APP/shared/storage/framework/views" "$APP/shared/storage/logs"

# .env is built from the values the workflow sent (.env.deploy). The app key is made once and kept.
if [ -f "$DIR/.env.deploy" ]; then
  KEY=""
  [ -f "$APP/shared/.env" ] && KEY="$(grep -E '^APP_KEY=' "$APP/shared/.env" | head -1 | cut -d= -f2- || true)"
  [ -n "$KEY" ] || KEY="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  { echo "APP_KEY=$KEY"; grep -v -E '^APP_KEY=' "$DIR/.env.deploy"; } > "$APP/shared/.env.new"
  chmod 600 "$APP/shared/.env.new"
  mv "$APP/shared/.env.new" "$APP/shared/.env"
  rm -f "$DIR/.env.deploy"
fi
[ -f "$APP/shared/.env" ] || { echo "No settings yet: run the workflow once with the GitHub secrets filled in."; exit 1; }

rm -rf "$DIR/storage"
ln -sfn "$APP/shared/storage" "$DIR/storage"
ln -sfn "$APP/shared/.env" "$DIR/.env"

cd "$DIR"
say "Database"
"$PHP" artisan migrate --force --no-interaction

say "Caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

say "Going live"
ln -sfn "releases/$REL" "$APP/current.next"
mv -Tf "$APP/current.next" "$APP/current"

if [ "$PUBLIC_MODE" = "copy" ]; then
  # For hosts that don't serve a symlinked web root: copy public/ and point index.php at the live release.
  [ -L "$WEB" ] && rm "$WEB"
  mkdir -p "$WEB"
  find "$WEB" -mindepth 1 -delete
  cp -a "$APP/current/public/." "$WEB/"
  sed "s#__DIR__.'/../#'$APP/current/#g" "$APP/current/public/index.php" > "$WEB/index.php"
elif [ ! -L "$WEB" ] || [ "$(readlink "$WEB")" != "$APP/current/public" ]; then
  if [ -e "$WEB" ] && [ ! -L "$WEB" ]; then
    BAK="$WEB.before-pollproof-$(date +%Y%m%d%H%M%S)"
    echo "Keeping the old web folder as $BAK"
    mv "$WEB" "$BAK"
  fi
  ln -sfn "$APP/current/public" "$WEB"
fi

"$PHP" artisan queue:restart

say "Tidying old releases (keeping $KEEP)"
ls -1dt "$APP"/releases/*/ 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
  [ "$(readlink -f "$old")" = "$(readlink -f "$APP/current")" ] || rm -rf "$old"
done

say "Live: $REL"
