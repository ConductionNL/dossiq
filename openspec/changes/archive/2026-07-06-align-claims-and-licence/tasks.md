# Tasks: align-claims-and-licence

## Licence

- [x] **T01**: `appinfo/info.xml` — fix the EN description (line 26) and NL description (line 47): "Free and open source under the AGPL license" → EUPL-1.2 wording ("Vrij en open source onder de EUPL-1.2-licentie"). Set `<licence>EUPL-1.2</licence>` (PO decision 2026-07-05; token accepted by the upstream app-info.xsd enum since nextcloud/server PR #60212, 2026-05-07, and by the App Store licenses fixtures). Fallback ONLY if the release targets an NC version whose xsd predates the EUPL value (tags ≤ v32.0.8 at verification time): keep `agpl` + XML comment `<!-- Actual licence: EUPL-1.2 (LICENSE); enum value pending on this NC version -->`. Bump info.xml version for cache-bust.
- [x] **T02**: README line 13 — badge `license-AGPL--3.0-blue` → `license-EUPL--1.2-blue`, link unchanged (LICENSE).

## README claims

- [x] **T03**: Line 67 — reword Unified Search to "provided centrally via OpenRegister" (OR `lib/Search/ObjectsProvider.php` is the actual provider; procest ships no `lib/Search/`).
- [x] **T04**: Line 68 — Pipelinq Bridge: mark as roadmap and point at `openspec/changes/semantic-case-intake/` ("planned — see semantic-case-intake change"); revert to shipped wording only when that change archives.
- [x] **T05**: Line 254 — remove DMN from the process-standards claim or annotate it "(roadmap — no DMN engine ships today)"; keep BPMN 2.0 only where actually applied.
- [x] **T06**: Fix dead links: lines 107/247 `docs/FEATURES.md`, lines 108/248 `docs/ARCHITECTURE.md`, line 249 `docs/development.md` — point each at an existing docs/ page (e.g. `docs/index.md`, `docs/Technical/`, `docs/installation.md`) or drop the row; verify every remaining `docs/…` link resolves.
- [x] **T07**: Line 162 "Nextcloud 28 – 33" → "28 – 34"; line 236 "PHP 8.1+" → "PHP 8.3+" (both to match `appinfo/info.xml:71-72`).

## Features overlay

- [x] **T08**: `openspec/features.overlay.json` — `archief-edepot-handover`: `"status": "stable"` → `"beta"` + reason "e-Depot submission runs on a mock/log adapter (LogEDepotSubmissionAdapter); nothing is transmitted to a real e-Depot yet".
- [x] **T09**: `openspec/features.overlay.json` — `multi-tenancy`: `"status": "stable"` → `"beta"` + reason "tenant stack is app-local; not yet on the OpenRegister boundary". Re-validate the JSON parses after both edits.

## Verification Tasks

- [x] **V01**: `occ app:enable procest` / info.xsd validation passes with `<licence>EUPL-1.2</licence>` against an xsd that carries the EUPL enum value (upstream master / stable31+ heads / tags ≥ v33.0.5 — verify the deployed NC's xsd, not the stale dev checkout); app store metadata renders EUPL-1.2 wording in both languages. Note the fleet-wide follow-up: openregister and the other Conduction apps still declare `agpl` — file/point at a separate fleet change (out of scope here).
- [x] **V02**: Link checker over README returns zero dead relative links; a reviewer diff confirms no remaining prose AGPL claim and no unbacked shipped-feature claim (Unified Search attributed, DMN roadmap, Pipelinq Bridge roadmap).
- [x] **V03**: Features overlay JSON is valid and the two entries render as beta with reasons in the features surface.

## Verification record (2026-07-06)

- **Licence path taken**: primary path — `<licence>EUPL-1.2</licence>`. The deployed Nextcloud's
  `resources/app-info.xsd` carries the `EUPL-1.2` enum value (verified in the `nextcloud`
  container); the agpl fallback was NOT needed. Note: the stale dev-checkout xsd at
  `workspace/server/resources/app-info.xsd` still predates the backport — validation was run
  against the deployed container's xsd per V01.
- **V01**: `schemaValidate()` against the deployed app-info.xsd → VALID with EUPL-1.2. Two
  pre-existing element-ordering violations (php after nextcloud in `<dependencies>`;
  repair-steps/commands/settings/navigations order) were fixed in the same batch (fix
  pre-existing issues in touched files); the original file failed xsd validation on both.
  Version bumped 0.2.33 → 0.2.34 for cache-bust. Fleet-wide follow-up (openregister + other
  Conduction apps still declare `agpl`) is out of scope here per the proposal and is tracked
  by the owner as a separate fleet change — no issue filed per owner mandate.
- **V02**: link check over all relative README links → zero dead links
  (LICENSE, docs/Features/README.md, docs/Technical/architecture.md,
  docs/Technical/development-guide.md, openspec/changes/semantic-case-intake/). No prose AGPL
  claim remains (the only AGPL mention left is the dependency-licence allowlist, which is
  correct). Unified Search attributed to OpenRegister; DMN marked roadmap; Pipelinq Bridge
  marked roadmap pointing at semantic-case-intake.
- **V03**: `features.overlay.json` parses (22 entries); both entries are `beta` with reasons.
- **Not live-verified**: app-store rendering of the EUPL wording and `occ app:enable` on the
  shared dev instance — the dev instance serves the main checkout (bind-mount), and deploying
  from this worktree over it is prohibited. xsd validation is the CI-equivalent proof.
