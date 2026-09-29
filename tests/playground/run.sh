#!/usr/bin/env bash
set -euo pipefail

# Starts a local WordPress with this plugin active, for the checks in
# tests/playground/README.md. Needs Node.js 20.18 or later, and nothing else:
# WordPress Playground runs PHP and the database inside Node.
#
#   ./tests/playground/run.sh                 WordPress latest, PHP 8.3
#   WP=6.4 PHP=8.1 ./tests/playground/run.sh  the oldest versions supported
#   PORT=9401 ./tests/playground/run.sh
#
# Extra arguments are passed on, for example to define the key in wp-config:
#   ./tests/playground/run.sh --define GALLOP_WP_API_KEY gallopwp_...
#
# The site is thrown away when the server stops. Log in at /wp-login.php with
# admin / password.

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$ROOT/tests/playground"
BLUEPRINT="${BLUEPRINT:-$HERE/blueprint.json}"

exec npx -y @wp-playground/cli@latest server \
    --wp="${WP:-latest}" \
    --php="${PHP:-8.3}" \
    --port="${PORT:-9400}" \
    --workers=1 \
    --mount="$ROOT:/wordpress/wp-content/plugins/gallop" \
    --mount="$HERE/mu-plugins:/wordpress/wp-content/mu-plugins" \
    --blueprint="$BLUEPRINT" \
    "$@"
