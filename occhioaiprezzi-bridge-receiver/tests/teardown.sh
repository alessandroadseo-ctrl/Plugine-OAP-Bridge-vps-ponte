#!/usr/bin/env bash
set -euo pipefail
# Remove ONLY explicitly labelled test resources. Never prune Docker globally.
for name in oap-receiver-php oap-receiver-db; do
  label="$(docker inspect -f '{{index .Config.Labels "oap.receiver.tests"}}' "$name" 2>/dev/null || true)"
  if [ "$label" = true ]; then docker rm -fv "$name"; else echo "Skip unlabelled/missing container: $name"; fi
done
label="$(docker network inspect -f '{{index .Labels "oap.receiver.tests"}}' oap-receiver-test 2>/dev/null || true)"
if [ "$label" = true ]; then docker network rm oap-receiver-test; fi
