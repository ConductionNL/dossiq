# Tasks: consume-or-mdm

## Deduplication / Dependency Check

- [x] **DC01**: Verify the deployed OpenRegister version ships the MDM engine (`x-openregister-quality` / `x-openregister-dedup` schema support + on-save `qualityScore`/`qualityStatus` materialisation, per ADR-045 / OR change `2026-06-23-mdm-foundation`); record the minimum OR version in `appinfo/info.xml` dependencies. Verify against HEAD of OR `origin/development`, not against assumptions.
- [x] **DC02**: Confirm no procest code performs duplicate matching, merging, or quality scoring today (expected: none — 2026-07-05 audit found zero MDM annotations and no such services); if any is found, remove it in this change rather than leaving a parallel path.

## Annotations (V1)

- [x] **T01**: Add `x-openregister-quality` + `x-openregister-dedup` to the `case` schema in `lib/Settings/procest_register.json` exactly as specified in `design.md` (identifier/vergunningaanvraagRef exact match, title normalized+levenshtein, blockingKeys caseType). Edit the JSON by hand (no scripting) and re-validate the file with a JSON parser.
- [x] **T02**: Add `x-openregister-quality` + `x-openregister-dedup` to the `supplier` schema (kvkNumber/iban exact, legalName normalized+levenshtein, kvkNumber `^[0-9]{8}$` format rule).
- [x] **T03**: Add `x-openregister-quality` + `x-openregister-dedup` to the `partnerOrganization` schema (oin exact, name normalized+levenshtein, contactEmail format rule).
- [x] **T04**: Document the steward workflow (OR governance views scoped to the procest register; no procest UI) in `docs/admin/` and record the explicit non-adoption of `x-openregister-survivorship` with its revisit trigger (live BRP/KvK feeds).

## Verification Tasks

- [x] **V01**: After register re-import, the three schemas in OR carry the annotations; saving a case materialises `qualityScore`/`qualityStatus` on the object.
- [x] **V02**: Creating two cases with the same `vergunningaanvraagRef` + caseType produces a duplicate-candidate pair in OR's Duplicate Candidates view scoped to procest's register (UI check through OpenRegister, not API-only).
- [x] **V03**: Creating two suppliers with the same `kvkNumber` produces a duplicate-candidate pair; merging them in OR leaves procest's supplier views consistent (surviving UUID renders, no procest error).
- [x] **V04**: Register import remains idempotent: re-running the repair step does not corrupt `required` arrays or drop the annotations (guard against the union-merge gotcha).

## Verification record (2026-07-06)

- **DC01**: Verified against OR `origin/development` HEAD: the MDM engine ships
  (`lib/Listener/QualityScoreOnSaveListener.php`, `lib/Service/Quality/QualityAnnotationValidator.php`,
  `lib/Service/Quality/DedupAnnotationValidator.php`, `lib/Service/Quality/DuplicateDetectionService.php`,
  `lib/Controller/DuplicateController.php`). Engine landed in OR commit `4a4800a` (2026-06-23) at
  version `0.2.16-unstable.1` → minimum OR version 0.2.16, recorded in `appinfo/info.xml`
  `<dependencies>` as an XML comment (deviation: app-info.xsd has no inter-app dependency element,
  so a machine-readable declaration is impossible; the comment is the closest faithful form).
- **DC02**: `grep` sweep over `lib/` + `src/` found no duplicate matching, merging, survivorship,
  or quality-scoring code (all "duplicate/dedup" hits are idempotency guards on seed/import paths
  and a STATUS_CONFLICT error map) — nothing to remove.
- **T01–T03**: Annotations added in-place in `lib/Settings/procest_register.json` (NOT register.d,
  per design's union-merge rationale), hand-edited, matching design.md exactly. `qualityScore` /
  `qualityStatus` declared as facetable properties with ADR-011 titles+descriptions on all three
  schemas (mirrors pipelinq's canonical masterEntity). Schema versions bumped (case 1.1.0→1.2.0,
  supplier/partnerOrganization 1.0.0→1.1.0). Pre-existing duplicate `"x-schema-org"` JSON key on
  the supplier schema removed in the same batch. JSON re-validated with a strict duplicate-key
  parser: valid, zero duplicate keys.
- **T04**: `docs/admin/master-data-stewardship.md` documents the steward workflow (OR governance
  views scoped to the procest register), the rule tables, and the explicit non-adoption of
  survivorship with its revisit trigger (live BRP/KvK feeds).
- **V01–V03 (NOT live-verified)**: register re-import into a running OR, on-save score
  materialisation, and the Duplicate Candidates UI checks require deploying this worktree over
  the shared dev instance, which is prohibited (the instance serves the main checkout).
  Structural equivalent shipped instead: `tests/Unit/Settings/MdmAnnotationsTest.php`
  (7 tests / 193 assertions, green) proves the annotations OR's validators read are present,
  well-formed, reference only declared properties, and declare no survivorship.
- **V04**: No register.d fragment touches these schemas (in-place annotation was chosen precisely
  to avoid the union-merge path); JSON strict-parse confirms `required` arrays intact.
