#!/usr/bin/env bash
# Prove the CURRENT flarum-warble checkout installs and boots against the
# LATEST flarum/realtime release. Used by .github/workflows/realtime-compat.yml
# and runnable locally:
#
#   DB_HOST=127.0.0.1 DB_PORT=3399 DB_USER=root DB_PASS=secret \
#     .github/scripts/realtime-compat.sh
#
# Exits non-zero the moment any stage fails:
#   1. composer resolution   — do our constraints admit the new realtime?
#   2. real Flarum install   — does the site install with both packages?
#   3. extension enable      — do both extensions boot (extenders run)?
set -euo pipefail

EXT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
WORKDIR="${WORKDIR:-$(mktemp -d)}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_NAME="${DB_NAME:-flarum_compat}"
export DB_USER="${DB_USER:-root}"
export DB_PASS="${DB_PASS:-root}"

echo "==> Extension under test: $EXT_DIR"
echo "==> Workdir: $WORKDIR"

LATEST="$(curl -fsS https://repo.packagist.org/p2/flarum/realtime.json \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["packages"]["flarum/realtime"][0]["version"];')"
[ -n "$LATEST" ] || { echo "Could not resolve latest flarum/realtime from Packagist"; exit 1; }
echo "==> Latest flarum/realtime: $LATEST"
# Expose the version to later workflow steps (issue title); no-op locally.
echo "realtime_version=$LATEST" >> "${GITHUB_OUTPUT:-/dev/null}"

echo "==> Creating fresh Flarum skeleton"
cd "$WORKDIR"
rm -rf site
# ^2.0@rc: the 2.x skeleton is still RC-tagged; once 2.0 goes stable this
# same constraint resolves to the stable release.
composer create-project "flarum/flarum:^2.0@rc" site --no-install --no-interaction
cd site

composer config repositories.warble path "$EXT_DIR"
composer require --no-update "linkrobins/flarum-warble:*@dev" "flarum/realtime:$LATEST"

echo "==> Stage 1: composer resolution against realtime $LATEST"
composer update --no-interaction --no-progress --prefer-dist

echo "==> Stage 2: real Flarum install"
# Fresh database every run so the script is rerunnable locally.
php -r '$p = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASS"));
$p->exec("DROP DATABASE IF EXISTS `".getenv("DB_NAME")."`");
$p->exec("CREATE DATABASE `".getenv("DB_NAME")."`");'
cat > install-compat.json <<JSON
{
    "debug": false,
    "baseUrl": "http://localhost",
    "databaseConfiguration": {
        "driver": "mysql",
        "host": "$DB_HOST",
        "port": $DB_PORT,
        "database": "$DB_NAME",
        "username": "$DB_USER",
        "password": "$DB_PASS",
        "prefix": ""
    },
    "adminUser": {
        "username": "admin",
        "password": "c0mp4t-ch3ck-only",
        "email": "admin@example.com"
    },
    "settings": {
        "forum_title": "Warble compat check"
    }
}
JSON
php flarum install --file=install-compat.json

echo "==> Stage 3: enable realtime + warble (boot smoke test)"
php flarum extension:enable flarum-realtime

# Once realtime is enabled it broadcasts to its websocket backend (default
# localhost:6001) when further extensions are enabled — with nothing
# listening, the enable itself dies. Run the stock daemon like a self-hosted
# forum would.
php flarum realtime:serve > realtime-serve.log 2>&1 &
DAEMON_PID=$!
trap 'kill "$DAEMON_PID" 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do
    php -r 'exit(@fsockopen("127.0.0.1", 6001) ? 0 : 1);' && break
    sleep 1
done

php flarum extension:enable linkrobins-warble
php flarum info

echo "==> COMPATIBLE: flarum-warble @ $(git -C "$EXT_DIR" rev-parse --short HEAD 2>/dev/null || echo 'local') vs flarum/realtime $LATEST"
