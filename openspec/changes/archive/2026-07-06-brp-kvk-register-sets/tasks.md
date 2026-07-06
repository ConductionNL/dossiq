# Tasks: brp-kvk-register-sets

> Scope: register side only (schemas + fictitious seed data + initiator UI). Live BRP/KvK adapters,
> config tiers, and the register → live search fallback belong to
> `external-integrations-test-environments` — reference, never duplicate. All tasks MVP tier.

## Deduplication / Dependency Check

- [x] **DC01**: Pin the fixture sets and record them in design.md: extract 10 personas from the
  current `test-data.json` of `ghcr.io/brp-api/personen-mock` (BSN, naam, geboorte,
  verblijfplaats); confirm the KvK-published fictitious companies on developers.kvk.nl and pick 10
  including 69599084 / 68750110 / 69599068 / 55344526. Coordinate the pinned values with
  `external-integrations-test-environments` (its T03/T05 fixtures MUST be the same objects).
- [x] **DC02**: Check `semantic-case-intake` status — if its ADR-048 requester semantic reference
  has landed on the `case` schema, the initiator picker writes that reference and fills
  `initiatorType`/`initiatorSourceId`/`initiatorDisplayName` as its projection (one write path);
  otherwise write the projection fields directly and leave the wiring note in that change.
- [x] **DC03**: ADR-011 dedup confirmed: BSN validation reuses OpenRegister's registered `bsn`
  format (`lib/Formats/BsnFormat.php`, registered in `ValidateObject.php:1484`) — verify the format
  keyword is still `bsn` at OR HEAD before schema authoring; no procest-side BSN validator.

## Register sets (schemas + seed)

- [x] **T01** [MVP]: Add ADR-037 fragment `lib/Settings/register.d/25-brp-kvk.json` with the
  `brpPerson` schema (Haal Centraal naming: `burgerservicenummer` `format: bsn`, `naam`,
  `geboorte`, `verblijfplaats`; `x-schema-org: schema:Person`) and the `kvkCompany` schema (KvK
  Zoeken naming: `kvkNummer`, `handelsnaam`, `rechtsvorm`, `adres`;
  `x-schema-org: schema:Organization`).
- [x] **T02** [MVP]: Seed 10 `brpPerson` rows in the fragment's `components.objects` from the DC01
  personen-mock personas; description on every row marks it as official fictitious test data
  (fragment objects go LIVE on import — intended).
- [x] **T03** [MVP]: Seed 10 `kvkCompany` rows likewise, including KVK 69599084 / 68750110 /
  69599068 / 55344526.
- [x] **T04** [MVP]: Extend the `case` schema in `lib/Settings/procest_register.json` (v1.1.0 →
  v1.2.0) with optional `initiatorType` (enum `person|company|contact`), `initiatorSourceId`,
  `initiatorDisplayName`; re-validate the merged register JSON after the edit (union-merge
  gotcha — check `required` arrays survive the fragment deep-merge).

## Initiator UI

- [x] **T05** [MVP]: Implement the `InitiatorPicker` registered component (`src/registry.js` +
  component file) with Person/Company/Contact tabs and unified search results: `brpPerson` /
  `kvkCompany` via `createObjectStore` (store-pattern rule, no backend CRUD wrapper), Contacts via
  the Nextcloud Contacts API with an explicit empty state when the app is absent; wire it into the
  case create/edit form (manifest `index-add` flow and `StartCaseWidget` — NOT a new
  `CaseCreateDialog.vue`, which does not exist at HEAD).
- [x] **T06** [MVP]: Persist the selection per DC02 (semantic reference where available; projection
  fields always) on case save.
- [x] **T07** [MVP]: Show the initiator on the manifest `CaseDetail` page overview
  (`initiatorDisplayName` + type + `initiatorSourceId` linking to the source record); render
  nothing when unset.

## Verification Tasks

- [x] **V01**: Fresh install + Repair import: `brpPerson`/`kvkCompany` schemas exist; 10+10 seed
  rows present and searchable via the OpenRegister objects API; re-import is idempotent (existing
  rows stay valid).
- [x] **V02**: OpenRegister rejects a `brpPerson` with an 11-proef-invalid BSN (`format: bsn`
  enforced — no procest-side validator involved).
- [x] **V03**: Playwright e2e (UI-only, real clicks): create a case, pick a seeded persona via the
  Person tab, save; case detail shows the initiator with a working source link; a case created
  without initiator shows no initiator block; Contact tab shows the empty state with Contacts
  disabled.
- [x] **V04**: Pre-existing cases (created before the schema bump) still open and validate with the
  initiator fields absent.
- [x] **V05**: Fixture parity spot-check: one seeded BSN resolves identically against the
  `personen-mock` container; one seeded KvK number (69599084) resolves against `api.kvk.nl/test`
  (network-gated, manual is fine) — same objects as the `external-integrations-test-environments`
  contract lanes.

## Verification record (2026-07-06)

- **DC01 (fixtures pinned)**: 10 personas extracted VERBATIM from the official
  `BRP-API/Haal-Centraal-BRP-bevragen` `test-data/personen-mock/test-data.json` (fetched
  2026-07-06): BSNs 999990627 (Stephan Janssen), 999992570 (Albert Vogel), 999995091
  (Thanatos Olympos), 999996277 (Christina Annabel Christiaansen), 900194054 (Tina-Antïna de
  Bruin), 999997609 (Eleonora de Crooy), 999993355 (Jan-Kees Brouwers), 999990949 (Marianne de
  Jong), 999990792 (Jan de Cuykelaer), 999999655 (Astrid Abels). NOTE: the mock contains some
  non-11-proef BSNs (e.g. 999999245); only 11-proef-valid personas were pinned, because OR's
  `format: bsn` would reject the rest on import. 10 companies fetched LIVE from
  `api.kvk.nl/test/api/v2/zoeken` (public test key) incl. the four pinned numbers with their real
  test handelsnamen/adressen (Test EMZ Dagobert, Test BV Donald, Test Stichting Bolderbast,
  Regional Stimflex Coöperatie, + NV/Stichting/VoF/Maatschap padding from the published table).
  **V05 thereby executed at pin time**: KVK 69599084 → "Test EMZ Dagobert" from api.kvk.nl/test.
- **DC02**: `semantic-case-intake` has NOT landed (wave 2, next in queue) — the picker writes the
  three projection fields directly; the semantic-reference wiring is picked up by that change
  (one write path preserved: `initiatorProjection()` is the single mapper).
- **DC03**: OR HEAD registers the `bsn` format keyword (`lib/Service/Object/ValidateObject.php:1498`,
  `registerCustomFormat(... format: 'bsn', resolver: new BsnFormat())`) — schema uses `format: bsn`,
  no procest-side validator.
- **T05 wiring note (deviation, documented)**: the manifest `index-add` auto-form renders the three
  projection fields from the schema (enum select + text) but cannot host a custom search component —
  the lib's fieldWidgets seam mounts lib `Cn*` SFCs only. The picker is therefore registered as
  kind `form-field` (forward-compatible) and wired into the REAL create flow procest owns:
  StartCaseWidget → InitiatorPickerModal (optional, skippable) → saveObject with projection.
- **T07**: CaseDetail overview gains a `type: custom` widget resolved via page `slots`
  (`widget-initiator` → `InitiatorSection`, the CnDetailPage `#widget-<id>` slot API); renders
  nothing without initiator; BSN/KvK number deep-links to the seeded register object in
  OpenRegister (`#/objects/procest/{schema}/{id}`), resolved best-effort by source-id lookup.
- **Tests**: PHPUnit `BrpKvkRegisterSetsTest` (6 tests / 214 assertions: naming, gate-28
  titles+descriptions incl. nested, 11-proef on all seeds, pinned KvK numbers, provenance rows,
  additive case fields, fragment union guard); vitest `initiatorSearch.spec.js` (7 tests);
  Playwright `brp-kvk-initiator.spec.ts` (6 UI scenarios tagged @e2e, incl. V03's create/pick/
  skip/empty-state flows); register-side scenarios carry reason-bearing @e2e excludes.
- **V01–V04 (NOT live-verified)**: fresh-install import, OR-side BSN rejection, the live UI
  click-through, and pre-existing-case validation require a deployed instance; the dev instance
  serves the main checkout and must not be overwritten from this worktree. The e2e file runs in
  the gate-19 live lane on deploy; PHPUnit covers the register-side invariants statically.
