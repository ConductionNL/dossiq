# Design: brp-kvk-register-sets

> Verified against procest HEAD (working tree, branch `fix/dashboard-header-sw-and-status-aggregation`)
> on 2026-07-06. The proposal predates the manifest migration; file-level claims are corrected below.

## Scope boundary (interlock, 2026-07-05)

This change owns the **register side**: BRP/KvK schemas, fictitious seed data (which double as
contract fixtures), and the initiator UI. The **live** BRP/KvK adapters, config tiers, and the
register → live-adapter search fallback are owned by `external-integrations-test-environments` —
referenced here, never duplicated. The seed objects MUST stay aligned with that change's fixture
sets so demo data and contract fixtures are the same objects.

## Context corrections against HEAD

| Proposal claim | Reality at HEAD | Consequence |
|---|---|---|
| `CaseCreateDialog.vue` gains initiator tabs | No such file. Case creation is manifest-driven: the Cases index page's add form (`index-add`, see the `create-case` tour step in `src/manifest.json`) and `src/views/widgets/StartCaseWidget.vue` (`objectStore.saveObject('case', …)`) | Initiator selection ships as a registered custom component (`src/registry.js`) wired into the case create/edit form, not a bespoke dialog |
| `CaseDetail.vue` shows initiator | Case detail is the manifest `CaseDetail` page (`src/manifest.json:650`) | Initiator display is a manifest-level addition (overview fields / section) on the `CaseDetail` page |
| Case schema gets initiator fields | `case` schema (`lib/Settings/procest_register.json:616`, v1.1.0) has no initiator fields today (the `initiator` hit at line 1970 is a contact-moment schema, unrelated) | Additive schema extension, re-validate merged register JSON (union-merge gotcha) |

## Decision 1 — Schemas + seed data ship as an ADR-037 fragment

New fragment `lib/Settings/register.d/25-brp-kvk.json` (ordering prefix below the existing `30-*`
fragments; deep-merged by `SettingsService::loadConfiguration()`, imported via the Repair step).
It mirrors the base shape and adds:

- `brpPerson` schema — Haal Centraal BRP Personen bevragen field naming: `burgerservicenummer`
  (`type: string`, `format: bsn` — reuses OpenRegister's registered `bsn` format,
  `lib/Formats/BsnFormat.php`, wired in `ValidateObject.php:1484`; ADR-011: no procest-side BSN
  validation), `naam` (geslachtsnaam, voornamen, voorvoegsel), `geboorte.datum`, `verblijfplaats`
  (straat, huisnummer, postcode, woonplaats). `x-schema-org: schema:Person`; ZGW mapping: Rol
  betrokkene `natuurlijk_persoon`.
- `kvkCompany` schema — KvK Zoeken API field naming: `kvkNummer`, `handelsnaam`, `rechtsvorm`,
  `adres` (straatnaam, huisnummer, postcode, plaats). `x-schema-org: schema:Organization`; ZGW
  mapping: Rol betrokkene `niet_natuurlijk_persoon`.
- `components.objects` seed rows: 10 persons + 10 companies (Decision 2).

These are **simplified test schemas** named after the API conventions — not registry connections
(the proposal's Risks section stands; live adapters live in
`external-integrations-test-environments`). Known trade-off: register.d fragment objects go LIVE
on import — intended here (dev/demo data), and every seed row is explicitly described as official
fictitious fixture data.

## Decision 2 — Seed rows are the contract fixtures (pinned, not invented)

- **Persons**: the 10 seed `brpPerson` rows are taken from the official BRP `personen-mock`
  `test-data.json` personas (`ghcr.io/brp-api/personen-mock`) — the same personas the BRP contract
  lane in `external-integrations-test-environments` runs against. Exact persona selection is pinned
  at implementation time from the mock's current `test-data.json` (DC01), never invented, so BSNs
  are guaranteed 11-proef-valid and mock-resolvable.
- **Companies**: the 10 seed `kvkCompany` rows include the KvK-published fictitious test companies,
  at minimum KVK **69599084, 68750110, 69599068, 55344526** (the pinned fixtures of the KvK
  contract lane), padded to 10 from the same published fictitious set (DC01).

## Decision 3 — Case schema initiator fields are a projection (one write path)

`case` schema gains three **optional, additive** properties in `lib/Settings/procest_register.json`
(version bump `1.1.0` → `1.2.0`): `initiatorType` (enum `person | company | contact`),
`initiatorSourceId` (BSN / KvK number / contact URI), `initiatorDisplayName` (string).

Coordination with `semantic-case-intake` (its proposal.md:37-41 already records this): the ADR-048
**requester semantic reference is canonical**; the initiator fields are its **display projection**.
One write path — the initiator picker writes the semantic reference where that change has landed
and fills the projection fields from it; it never introduces a second requester field. If
`semantic-case-intake` has not landed, the picker writes the projection fields directly and the
projection wiring is picked up by that change (checked in DC02).

## Decision 4 — Initiator UI: registered components on the manifest seams

- **Selection** (create flow): an `InitiatorPicker` component registered in `src/registry.js` and
  wired into the case create/edit form, with type tabs Person / Company / Contact and a single
  search box. Searches are frontend-only (thin-client rule — no procest backend CRUD):
  `brpPerson` / `kvkCompany` via the OpenRegister objects API (`createObjectStore` — store-pattern
  rule), Contacts via the Nextcloud Contacts API (graceful empty state when the Contacts app is
  absent). The register → live-adapter fallback tier is `external-integrations-test-environments`'
  seam: this picker queries the register tier only; that change swaps the search source behind its
  config modes.
- **Display** (detail): the `CaseDetail` manifest page shows `initiatorDisplayName` +
  `initiatorType` in the overview, with `initiatorSourceId` as the link/reference to the source
  record (register object or contact).

## Risks / Trade-offs

- Fragment seed objects go live on every import — acceptable for fictitious fixture data; rows are
  labelled as such.
- Union-merge of the register JSON can corrupt `required` arrays — merged output is re-validated
  after every edit (V-tasks).
- Contacts search silently empty without the Contacts app — surfaced as an explicit empty state,
  not an error.
