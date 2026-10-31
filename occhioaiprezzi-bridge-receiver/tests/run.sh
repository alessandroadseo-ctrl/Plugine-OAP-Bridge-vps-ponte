#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
docker exec oap-receiver-php sh -c 'find wp-content/plugins/occhioaiprezzi-bridge-receiver -name "*.php" -exec php -l {} \;'
docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/integration.php
python3 "$here/concurrency.py"
python3 "$here/http_client.py"
