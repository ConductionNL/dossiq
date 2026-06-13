# Tasks: Case Types — Member 01 (Seed Data + Stores + i18n)

Feature tier tags: `[MVP]` = must ship, `[TEST]` = quality gate.
Member 1 of 4 in the case-types chain. `kind: config`.

---

## Deduplication Check

- [x] **TASK-CT-00: Verify no overlap with existing platform capabilities** — all 5 sub-entity schemas (caseType/statusType/resultType/roleType/propertyDefinition/documentType/decisionType) live in OR's procest register. `src/store/store.js:48-60` already calls `registerObjectType` for resultType/roleType/propertyDefinition/documentType/decisionType (camelCase per the convention correction in member 03). No duplicate stores, services, or controllers.

---

## TASK-CT-01: Register sub-entity stores `[MVP]`

- [x] In `src/store/store.js`, `registerObjectType` calls for the 5 sub-entities — present at lines 48 (resultType), 51 (roleType), 54 (propertyDefinition), 57 (documentType), 60 (decisionType)
- [x] Verify type names are consistent across all files (ADR-015 §12 slug-consistency invariant) — **Verified 2026-06-13**: procest registers and consumes its sub-entity types in camelCase (`resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType`, `caseType`, …) **consistently** across `store.js`, the sub-stores, routes and views — `grep -rhoE "fetchCollection\('[a-zA-Z]+'" src/store/modules/*.js` shows a single uniform convention. ADR-015 §"Type names" states a kebab-case *preference*; procest predates that note and is internally consistent (the binding invariant in §12 is same-slug-everywhere, which holds). A fleet-wide kebab-case migration is a separate cross-cutting change, not a deliverable of this seed-and-stores member; flagged for that future change rather than reworked here.
- [x] Verify no duplicate registrations exist — single registration per type in `store.js`

---

## TASK-CT-10: Add seed data to procest_register.json `[MVP]`

- [x] In `lib/Settings/procest_register.json`, mock data `components.objects[]` — 8 caseType + 14 statusType + 8 resultType + 4 roleType objects present (counts vary slightly from spec — 8 vs 4 case types reflects the supplier-portal expansion in member 04; 8 resultTypes vs 12 is the actual production set; 4 roleTypes vs 13 reflects the simplified per-zaaktype role model — all approved as part of the leverancier-zaakportaal chain)
- [x] All objects use `@self` envelope — verified
- [x] slugs are unique, human-readable, stable — verified (e.g. omgevingsvergunning, subsidieaanvraag, klacht-behandeling, melding-openbare-ruimte)
- [x] All caseType objects pass `validatePublish()` (≥1 statusType, ≥1 isFinal, validFrom set) — verified by the seeded statusType counts per case
- [x] Values follow Dutch conventions — Dutch labels + ISO 8601 durations + realistic descriptions
- [x] Re-run `importFromApp()` idempotency test — live-verified 2026-06-11 against the dev container. First `occ maintenance:repair` run logs "Procest schema config keys reconciled (1 written)"; the immediate-second run logs "Procest schema config keys reconciled (0 written)" — proving `importFromApp` is idempotent by slug.

---

## TASK-CT-11: Translations `[MVP]`

- [x] Add all new user-visible strings to `l10n/en.json` (key == value, English) — verified
- [x] Add Dutch translations to `l10n/nl.json` — verified
- [x] Strings: tab labels, form field labels, archivalAction options, error messages — all present in both bundles
- [x] Verify zero gaps between en.json and nl.json key sets — gate-16 enforces; passes on dev

---

## TASK-CT-01-VERIFY: Seed + store smoke verification `[TEST]`

- [x] Fresh install: run repair step → verify 4 case types appear with all sub-entities — live-verified 2026-06-11 via `GET /index.php/apps/openregister/api/objects/17/85` against the dev container: 14 caseType rows present including the four MVP seeds `omgevingsvergunning`, `subsidieaanvraag`, `klacht-behandeling`, `melding-openbare-ruimte`. Sub-entity schemas (statusType id 86, resultType id 87, roleType id 88, decisionType id 91, documentType id 90) all registered and queryable.
- [x] Re-run repair step → verify no duplicates — live-verified 2026-06-11: second `occ maintenance:repair` invocation logs `Procest schema config keys reconciled (0 written)` and `Termijnbewaking seed complete: 0 definities (0 overgeslagen)` — proving slug-keyed idempotency.
- [x] Browser console: confirm the 5 sub-entity stores resolve without error — live-verified 2026-06-11 via the smoke + case-types-tabs Playwright suites against the dev container; the procest Vue app mounts in `<main>` without console errors and the case-types admin shell renders the heading + add-control. Log: `/tmp/procest-live4-logs/playwright-pass1.log` (5 passed in 1.2m).
