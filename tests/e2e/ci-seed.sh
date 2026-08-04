#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Procest Contributors
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Procest's OpenRegister register + schemas on a freshly installed
# Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/procest/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED — AND WHY IT REPLACED `php occ maintenance:repair`
# --------------------------------------------------------------------
# The caller previously passed `php occ maintenance:repair` here. That is the
# IRepairStep path, and it CANNOT provision this register:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import is denied outright with
#      "User 'Anonymous' does not have permission to 'create' objects in
#      schema '…'". `Repair\InitializeSettings::run()` catches `\Throwable` and
#      downgrades it to `$output->warning(...)`, so both `occ app:enable
#      procest` and `occ maintenance:repair` still exit 0.
#   2. The repair step calls `loadConfiguration(force: false)`. The non-forced
#      path is version-guarded: it can advance the recorded configuration
#      version WITHOUT applying the register, so a second run then sees
#      "already current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the register simply is
# not there. The e2e suite's failure mode in that state is every
# `helpers/fixtures.ts` call failing its `expect(res.ok())` on a 404 from
# `/apps/openregister/api/objects/procest/<schema>`, plus every UI spec timing
# out on an empty case list — messages that accuse the fixtures and the
# selectors, not the missing import.
#
# So this script does the import EXPLICITLY over the admin HTTP API (which has
# a real session and passes RBAC), forced, and then VERIFIES the register and
# schemas actually exist. A failed provision becomes ONE loud step failure here
# instead of a hundred misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side and re-running only
# re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD / NC_ADMIN_USER / NC_ADMIN_PASS.
# Accept all of them, and fall back to the CI runner's own
# `php -S 0.0.0.0:8080` only when actually running on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently import a register into
# somebody else's environment. Off CI, an unset target is a hard error. Same
# contract as tests/e2e/base-url.ts.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

# ── 1. Import the Procest configuration ──────────────────────────────────────
# Procest has no bespoke import route, but it does not need one: its
# `appinfo/routes.php` returns `\OCA\OpenRegister\AppHost\Routes::standard(...)`,
# whose canonical table ships `settings#load` at POST /api/settings/load. On
# procest that name resolves to
# OCA\Procest\Controller\SettingsController::load(), which calls
# `loadConfiguration(force: true)` — precisely the forced import the repair step
# cannot perform.
#
# ⚠️ Prefer this over OpenRegister's generic importer, and not only for
# convenience: `SettingsService::loadConfiguration()` deep-merges every
# `lib/Settings/register.d/*.json` fragment on top of the
# `procest_register.json` monolith (ADR-037) before importing, and folds the
# fragment-set hash into the version. Posting the monolith alone to the generic
# importer would provision 64 schemas but MISS the ~67 schema keys the 20
# fragments contribute (brp-kvk, kcc-werkplek, deelzaak, termijnbewaking,
# document-zaakdossier, dmn-decision-tables, …) — which is exactly the set the
# `spec-coverage/` specs exercise.
#
# It is admin-only (`#[AuthorizedAdminSetting(AdminSettings::class)]`), so HTTP
# Basic as admin is required. `OCS-APIRequest: true` is load-bearing, not
# decoration: the method carries no `#[NoCSRFRequired]`, and Nextcloud's
# `Request::passesCSRFCheck()` short-circuits to true on that header (the
# strict-cookie precondition is satisfied because a Basic-auth request carries
# no session cookie at all). Without the header this POST is rejected as a CSRF
# failure.
IMPORT_URL="${BASE}/index.php/apps/procest/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL} (forced import, fragments merged)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		--max-time 900 \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] settings#load HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

# HTTP 200 is necessary but NOT sufficient: SettingsController::load() returns
# `{"success": false, "message": "..."}` with a 200 when the import itself
# failed (OpenRegister missing, unreadable JSON, ConfigurationService throwing).
# Treat anything that is not an explicit success as a reason to try the generic
# importer below, and let the verification step decide the outcome.
IMPORT_OK=0
if [ "$IMPORT_CODE" = "200" ] && grep -q '"success":[[:space:]]*true' "$IMPORT_BODY"; then
	IMPORT_OK=1
	echo "[ci-seed] procest settings#load reported success."
else
	echo "[ci-seed] procest settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of procest's own controller wiring, so it still provisions the
# core register if `settings#load` is unavailable (e.g. an OpenRegister build
# whose AppHost route table predates `settings#load`). Admin-only. It reads the
# upload under the literal form key `file`; a raw JSON request body is NOT one
# of its accepted shapes. `force` is compared `=== 'true' || === true` there, so
# the form-encoded string is fine.
#
# NOTE this fallback posts the MONOLITH ONLY — it cannot merge register.d
# fragments (that merge lives in procest's SettingsService). It is a degraded
# path that gets the core case/caseType/statusType/task/workflowTemplate schemas
# in place; the verification below is what decides whether that was enough.
if [ "$IMPORT_OK" != "1" ]; then
	REGISTER_JSON="${APP_DIR}/lib/Settings/procest_register.json"
	if [ ! -f "$REGISTER_JSON" ]; then
		echo "::error::procest_register.json not found at ${REGISTER_JSON}."
		exit 1
	fi

	OR_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${OR_URL} (file=procest_register.json, force=true)"
	OR_BODY="$(mktemp)"
	OR_CODE="$(
		curl -sS -o "$OR_BODY" -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST \
			-H 'OCS-APIRequest: true' \
			-F "file=@${REGISTER_JSON}" \
			-F 'force=true' \
			-F 'appId=procest' \
			--max-time 900 \
			"$OR_URL" || echo 000
	)"
	echo "[ci-seed] configurations/import HTTP ${OR_CODE}"
	head -c 2000 "$OR_BODY"; echo
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# An import reporting success is not the same as the register existing. Verify
# against OpenRegister directly, using the same slugs the e2e fixtures resolve
# by (helpers/fixtures.ts builds every object URL as
# /apps/openregister/api/objects/procest/<schema>).
#
# ⚠️ The required slugs below are READ OUT of lib/Settings/procest_register.json
# (`components.registers.procest.slug` and `components.schemas.<k>.slug`), NOT
# derived by kebab-casing a display name. OpenRegister resolves a schema segment
# with `LOWER(slug)`, so `caseType` is correct and `case-type` is not — the
# mismatch would be structural, not a casing difference.
#
# The HTTP status is captured and checked SEPARATELY from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if you
# only look at the parsed list. A wrong lookup manufactures an absence for free,
# so the two are reported as different errors.
verify() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
required = {
    # components.registers.procest.slug
    'registers': ['procest'],
    # components.schemas.<key>.slug — every one of these is exercised by
    # tests/e2e/helpers/fixtures.ts (createObject / seedCase / seedStateMachine
    # / ensureCaseType / cleanupRunObjects).
    'schemas': ['case', 'caseType', 'statusType', 'workflowTemplate', 'task', 'complaint'],
}[kind]
with open(path) as fh:
    raw = fh.read()
if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the '
          f'slug list below proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present ({len(slugs)}): {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::Procest {kind} missing after import: {missing}')
    print('::error::tests/e2e/helpers/fixtures.ts cannot seed a case, caseType, '
          'statusType, workflowTemplate, task or complaint without them, and every '
          'UI spec then asserts against an empty list.')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
REG_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$REG_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" || echo 000)"
verify "$REG_BODY" registers "$REG_CODE"

SCH_BODY="$(mktemp)"
SCH_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$SCH_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" || echo 000)"
verify "$SCH_BODY" schemas "$SCH_CODE"

# The register existing is still not the same as it being READABLE by the admin
# session the fixtures use. `helpers/fixtures.ts#listObjects` asserts
# `expect(res.ok())` on exactly this URL, so a 4xx here turns into an assertion
# failure inside whichever spec happened to run first. Probe it here so that
# failure mode has a name.
for schema in case caseType statusType; do
	OBJ_CODE="$(curl -sS -o /dev/null -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/procest/${schema}?_limit=1" || echo 000)"
	echo "[ci-seed] objects/procest/${schema} probe -> ${OBJ_CODE}"
	if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
		echo "::error::The procest ${schema} collection is not readable (HTTP ${OBJ_CODE})."
		echo "::error::tests/e2e/helpers/fixtures.ts asserts res.ok() on this URL; every seeding spec would fail here."
		exit 1
	fi
done

echo "[ci-seed] Procest register + schemas provisioned."

# ── 3. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and that cost lands entirely
# on whichever spec happens to run first. Warming it here puts that cost in the
# environment-preparation step where it belongs, rather than inside an assertion
# timeout that would then have to keep drifting upward.
#
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and below.
for path in \
	"/index.php/apps/procest/" \
	"/index.php/apps/procest/api/settings" \
	"/index.php/apps/procest/api/manifest" \
	"/index.php/settings/admin/procest" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/procest/js/…` on the CI runner,
# `/custom_apps/procest/js/…` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/procest/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*procest-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# Note that this gate reads the SERVED response, not the file on disk, and it is
# placed at the very end so that a run which reaches the specs has provably been
# able to fetch real JavaScript for the SPA. `global-setup.ts#ensureBundleBuilt`
# only does an `fs.existsSync()` and would happily accept a zero-byte file, so
# this is the check that actually has teeth.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	BUNDLE_BYTES="$(printf '%s\n' "$BUNDLE_INFO" | awk '{print $3}')"
	case "$BUNDLE_INFO" in
		*javascript*)
			if [ "${BUNDLE_BYTES:-0}" -lt 10000 ] 2>/dev/null; then
				echo "::error::The Procest frontend bundle served as JavaScript but is only ${BUNDLE_BYTES} bytes."
				echo "::error::A truncated or empty bundle mounts no Vue app; every UI spec would fail on a selector timeout."
				exit 1
			fi
			echo "[ci-seed] bundle verified as JavaScript (${BUNDLE_BYTES} bytes)."
			;;
		*)
			echo "::error::The Procest frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
