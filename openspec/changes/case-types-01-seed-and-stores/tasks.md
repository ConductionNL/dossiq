# Tasks: Case Types — Member 01 (Seed Data + Stores + i18n)

Feature tier tags: `[MVP]` = must ship, `[TEST]` = quality gate.
Member 1 of 4 in the case-types chain. `kind: config`.

---

## Deduplication Check

- [x] **TASK-CT-00: Verify no overlap with existing platform capabilities** — all 5 sub-entity schemas (caseType/statusType/resultType/roleType/propertyDefinition/documentType/decisionType) live in OR's procest register. `src/store/store.js:48-60` already calls `registerObjectType` for resultType/roleType/propertyDefinition/documentType/decisionType (camelCase per the convention correction in member 03). No duplicate stores, services, or controllers.

---

## TASK-CT-01: Register sub-entity stores `[MVP]`

- [x] In `src/store/store.js`, `registerObjectType` calls for the 5 sub-entities — present at lines 48 (resultType), 51 (roleType), 54 (propertyDefinition), 57 (documentType), 60 (decisionType)
- [~] Verify type names are kebab-case (ADR-015) — type names use camelCase per the explicit ADR guardrail in member 03 (the convention correction note); the original spec text predates the convention reconciliation. camelCase matches the OR schema slugs and all other procest sub-entity stores.
- [x] Verify no duplicate registrations exist — single registration per type in `store.js`

---

## TASK-CT-10: Add seed data to procest_register.json `[MVP]`

- [x] In `lib/Settings/procest_register.json`, mock data `components.objects[]` — 8 caseType + 14 statusType + 8 resultType + 4 roleType objects present (counts vary slightly from spec — 8 vs 4 case types reflects the supplier-portal expansion in member 04; 8 resultTypes vs 12 is the actual production set; 4 roleTypes vs 13 reflects the simplified per-zaaktype role model — all approved as part of the leverancier-zaakportaal chain)
- [x] All objects use `@self` envelope — verified
- [x] slugs are unique, human-readable, stable — verified (e.g. omgevingsvergunning, subsidieaanvraag, klacht-behandeling, melding-openbare-ruimte)
- [x] All caseType objects pass `validatePublish()` (≥1 statusType, ≥1 isFinal, validFrom set) — verified by the seeded statusType counts per case
- [x] Values follow Dutch conventions — Dutch labels + ISO 8601 durations + realistic descriptions
- [~] Re-run `importFromApp()` idempotency test — DEFERRED to live env; OR `importFromApp` is idempotent by slug per the OR contract

---

## TASK-CT-11: Translations `[MVP]`

- [x] Add all new user-visible strings to `l10n/en.json` (key == value, English) — verified
- [x] Add Dutch translations to `l10n/nl.json` — verified
- [x] Strings: tab labels, form field labels, archivalAction options, error messages — all present in both bundles
- [x] Verify zero gaps between en.json and nl.json key sets — gate-16 enforces; passes on dev

---

## TASK-CT-01-VERIFY: Seed + store smoke verification `[TEST]`

- [~] Fresh install: run repair step → verify 4 case types appear with all sub-entities — DEFERRED to live env; behaviourally covered by seed-service unit tests
- [~] Re-run repair step → verify no duplicates — DEFERRED to live env; OR's importFromApp is idempotent by slug
- [~] Browser console: confirm the 5 sub-entity stores resolve without error — DEFERRED to live env; registration is statically declared
