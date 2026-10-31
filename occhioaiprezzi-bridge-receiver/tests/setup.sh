#!/usr/bin/env bash
set -euo pipefail
plugin_root="$(cd "$(dirname "$0")/.." && pwd)"
test_root="${OAP_TEST_ROOT:-$plugin_root/../work/oap-receiver-tests}"
for name in oap-receiver-db oap-receiver-php; do
  if docker inspect "$name" >/dev/null 2>&1; then echo "Container $name already exists; use the existing isolated setup or review teardown.sh." >&2; exit 1; fi
done
mkdir -p "$test_root/artifacts"
if [ ! -d "$test_root/wordpress" ]; then
  git clone --depth 1 --branch 6.8.3 https://github.com/WordPress/WordPress.git "$test_root/wordpress"
fi
curl -fsSL https://github.com/woocommerce/woocommerce/releases/download/10.3.5/woocommerce.zip -o "$test_root/woocommerce.zip"
printf '%s  %s\n' '8f720a1a614ef9ba7e6f71766a9f3ef972f01afa0312dfcd6259613c0db495cf' "$test_root/woocommerce.zip" | sha256sum -c -
python3 -m zipfile -e "$test_root/woocommerce.zip" "$test_root/wordpress/wp-content/plugins"
cp "$plugin_root/tests/wp-config.php" "$test_root/wordpress/wp-config.php"
printf '%s\n' '<?php if (preg_match("/\.(css|png|jpg|ico)$/",parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH))) return false; require "/var/www/html/index.php";' > "$test_root/router.php"
docker pull wordpress:cli-php8.3
docker pull mariadb:11.4
docker network create --label oap.receiver.tests=true oap-receiver-test
docker run -d --name oap-receiver-db --label oap.receiver.tests=true --network oap-receiver-test -e MARIADB_ROOT_PASSWORD=oap-local-test-only -e MARIADB_DATABASE=oap_test mariadb:11.4
ready=false
for attempt in $(seq 1 60); do
  if docker exec oap-receiver-db mariadb-admin ping -uroot -poap-local-test-only --silent >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
[ "$ready" = true ] || { echo 'MariaDB not ready' >&2; exit 1; }
docker run -d --name oap-receiver-php --label oap.receiver.tests=true --network oap-receiver-test --user "$(id -u):$(id -g)" \
  -v "$test_root/wordpress:/var/www/html" -v "$plugin_root:/var/www/html/wp-content/plugins/occhioaiprezzi-bridge-receiver:ro" \
  -v "$test_root/artifacts:/oap-artifacts" -v "$test_root/router.php:/router.php:ro" -p 127.0.0.1:18086:8080 \
  --entrypoint sh wordpress:cli-php8.3 -c 'php -S 0.0.0.0:8080 -t /var/www/html /router.php'
docker exec oap-receiver-php wp --allow-root core install --url=https://receiver.test --title='OAP isolated tests' --admin_user=oap_test_admin --admin_password=oap-local-test-password --admin_email=test@example.invalid --skip-email
docker exec oap-receiver-php wp --allow-root plugin activate woocommerce occhioaiprezzi-bridge-receiver
docker exec oap-receiver-php wp --allow-root rewrite structure '/%postname%/'
