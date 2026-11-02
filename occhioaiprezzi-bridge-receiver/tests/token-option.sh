#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
config="${OAP_TEST_ROOT:-$here/../../work/oap-receiver-tests}/wordpress/wp-config.php"
grep -q "define('DB_NAME','oap_test')" "$config"
backup="$(mktemp)"
cp "$config" "$backup"
trap 'cp "$backup" "$config"; rm -f "$backup"' EXIT
python3 - "$config" <<'PY'
import sys
p=sys.argv[1]
s=open(p).read()
line="define('OAP_BRIDGE_TOKEN',str_repeat('local-test-',6));"
assert line in s
open(p,'w').write(s.replace(line,''))
PY
docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/token-option.php
