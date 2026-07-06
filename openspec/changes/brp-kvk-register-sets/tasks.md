# Tasks: brp-kvk-register-sets

> Scope: register side only (schemas + fictitious seed data + initiator UI). Live BRP/KvK adapters,
> config tiers, and the register → live search fallback belong to
> `external-integrations-test-environments` — reference, never duplicate. All tasks MVP tier.

## Deduplication / Dependency Check

- [ ] **DC01**: Pin the fixture sets and record them in design.md: extract 10 personas from the
  current `test-data.json` of `ghcr.io/brp-api/personen-mock` (BSN, naam, geboorte,
  verblijfplaats); confirm the KvK-published fictitious companies on developers.kvk.nl and pick 10
  including 69599084 / 68750110 / 69599068 / 55344526. Coordinate the pinned values with
  `external-integrations-test-environments` (its T03/T05 fixtures MUST be the same objects).
- [ ] **DC02**: Check `semantic-case-intake` status — if its ADR-048 requester semantic reference
  has landed on the `case` schema, the initiator picker writes that reference and fills
  `initiatorType`/`initiatorSourceId`/`initiatorDisplayName` as its projection (one write path);
  otherwise write the projection fields directly and leave the wiring note in that change.
- [ ] **DC03**: ADR-011 dedup confirmed: BSN validation reuses OpenRegister's registered `bsn`
  format (`lib/Formats/BsnFormat.php`, registered in `ValidateObject.php:1484`) — verify the format
  keyword is still `bsn` at OR HEAD before schema authoring; no procest-side BSN validator.

## Register sets (schemas + seed)

- [ ] **T01** [MVP]: Add ADR-037 fragment `lib/Settings/register.d/25-brp-kvk.json` with the
  `brpPerson` schema (Haal Centraal naming: `burgerservicenummer` `format: bsn`, `naam`,
  `geboorte`, `verblijfplaats`; `x-schema-org: schema:Person`) and the `kvkCompany` schema (KvK
  Zoeken naming: `kvkNummer`, `handelsnaam`, `rechtsvorm`, `adres`;
  `x-schema-org: schema:Organization`).
- [ ] **T02** [MVP]: Seed 10 `brpPerson` rows in the fragment's `components.objects` from the DC01
  personen-mock personas; description on every row marks it as official fictitious test data
  (fragment objects go LIVE on import — intended).
- [ ] **T03** [MVP]: Seed 10 `kvkCompany` rows likewise, including KVK 69599084 / 68750110 /
  69599068 / 55344526.
- [ ] **T04** [MVP]: Extend the `case` schema in `lib/Settings/procest_register.json` (v1.1.0 →
  v1.2.0) with optional `initiatorType` (enum `person|company|contact`), `initiatorSourceId`,
  `initiatorDisplayName`; re-validate the merged register JSON after the edit (union-merge
  gotcha — check `required` arrays survive the fragment deep-merge).

## Initiator UI

- [ ] **T05** [MVP]: Implement the `InitiatorPicker` registered component (`src/registry.js` +
  component file) with Person/Company/Contact tabs and unified search results: `brpPerson` /
  `kvkCompany` via `createObjectStore` (store-pattern rule, no backend CRUD wrapper), Contacts via
  the Nextcloud Contacts API with an explicit empty state when the app is absent; wire it into the
  case create/edit form (manifest `index-add` flow and `StartCaseWidget` — NOT a new
  `CaseCreateDialog.vue`, which does not exist at HEAD).
- [ ] **T06** [MVP]: Persist the selection per DC02 (semantic reference where available; projection
  fields always) on case save.
- [ ] **T07** [MVP]: Show the initiator on the manifest `CaseDetail` page overview
  (`initiatorDisplayName` + type + `initiatorSourceId` linking to the source record); render
  nothing when unset.

## Verification Tasks

- [ ] **V01**: Fresh install + Repair import: `brpPerson`/`kvkCompany` schemas exist; 10+10 seed
  rows present and searchable via the OpenRegister objects API; re-import is idempotent (existing
  rows stay valid).
- [ ] **V02**: OpenRegister rejects a `brpPerson` with an 11-proef-invalid BSN (`format: bsn`
  enforced — no procest-side validator involved).
- [ ] **V03**: Playwright e2e (UI-only, real clicks): create a case, pick a seeded persona via the
  Person tab, save; case detail shows the initiator with a working source link; a case created
  without initiator shows no initiator block; Contact tab shows the empty state with Contacts
  disabled.
- [ ] **V04**: Pre-existing cases (created before the schema bump) still open and validate with the
  initiator fields absent.
- [ ] **V05**: Fixture parity spot-check: one seeded BSN resolves identically against the
  `personen-mock` container; one seeded KvK number (69599084) resolves against `api.kvk.nl/test`
  (network-gated, manual is fine) — same objects as the `external-integrations-test-environments`
  contract lanes.
