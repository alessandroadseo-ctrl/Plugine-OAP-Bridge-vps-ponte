#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
docker exec oap-receiver-php sh -c 'find wp-content/plugins/occhioaiprezzi-bridge-receiver -name "*.php" -exec php -l {} \;'
docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/integration.php
python3 "$here/concurrency.py"
python3 "$here/public_base.py"
python3 "$here/http_client.py"
docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/admin.php

bash "$here/token-option.sh"

docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/affiliates.php
python3 "$here/affiliates_http.py"
python3 "$here/compara_http.py"

docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/regeneration.php
for mode in missing reused invalid-token invalid-worker old-inbound valid; do
  docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/regeneration-config.php "$mode"
done

docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/deletion.php
python3 "$here/deletion_http.py"
