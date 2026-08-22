#!/usr/bin/env bash
#
# Run a command inside the dev-web container, at the plugin root.
# Usage: ./tools/dev.sh composer install
#        ./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist

set -euo pipefail

PLUGIN_DIR=/var/www/html/planetozh.com/blog/wp-content/plugins/ozh-markdown

exec docker exec -i dev-web bash --login -c "cd ${PLUGIN_DIR} && $(printf '%q ' "$@")"
