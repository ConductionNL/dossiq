#!/usr/bin/env bash
#
# Dossiq ZGW Newman orchestrator.
#
# Runs every *.postman_collection.json in this directory against a live
# Nextcloud instance serving the dossiq app (openregister-style orchestrator;
# run it locally — CI newman wiring lives in .github/workflows/code-quality.yml
# via the shared quality pipeline's `enable-newman` input, which this repo
# currently leaves off). Each collection is self-seeding
# and idempotent (creates the objects it needs and deletes them in teardown).
#
# Usage:
#   ./run-all.sh                                     # localhost:8080, admin:admin
#   BASE_URL=http://localhost:8080 ./run-all.sh
#   ADMIN_USER=admin ADMIN_PASS=admin ./run-all.sh
#
# Uses a globally-installed `newman` if present, otherwise `npx newman`.
# Runs are serialised via flock (when available) so concurrent CI agents do
# not trip the Nextcloud brute-force protection.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-dossiq.lock"
if [ "${DOSSIQ_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export DOSSIQ_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The ZGW endpoints are served at /index.php/apps/dossiq by default.
BASE_URL="${BASE_URL:-http://localhost:8080/index.php/apps/dossiq}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

status=0
for collection in "${SCRIPT_DIR}"/*.postman_collection.json; do
  [ -e "${collection}" ] || continue
  echo "=== Running $(basename "${collection}") ==="
  if ! "${NEWMAN[@]}" run "${collection}" \
      --env-var "base_url=${BASE_URL}" \
      --env-var "admin_user=${ADMIN_USER}" \
      --env-var "admin_pass=${ADMIN_PASS}" \
      --reporters cli \
      --color on \
      "$@"; then
    status=1
  fi
done

exit "${status}"
