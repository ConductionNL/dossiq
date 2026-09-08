---
kind: config
depends_on: [requester-on-the-case, parties-on-the-case]
---

# Proposal: contacts-domain

Round 2 competitor analysis, section 1 of
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md` and rows
A03, A04, A17, A35, B01, B20 and B22 of `_round2/compare/placement.md`. Rung 5
on the placement ladder: a new top-level domain, spending one of the two
spare entries ADR-097 leaves dossiq. Ruben decided it on 2026-09-08
(`_round2/compare/decisions.md` D9): Contacts becomes a top-level domain, and
D13 applies, so every new id and property is English. One noun: the people
and organisations dossiq knows.

## Why

You cannot find a person or an organisation in dossiq. You reach a citizen
only through a case that already names them, and the case's Contacts tab is
inert (`dossiq-defect-triage.md` #2). `brpPerson` and `kvkCompany`
(`lib/Settings/register.d/25-brp-kvk.json`) hold the seeded personas and
companies, `contactmoment` (`register.d/40-kcc-werkplek.json`) holds the
logged calls, and `case.requester` points at one of the first two, yet no
page lists any of them, and nothing shows a person with their cases.

Two of three competitors put a contact surface at the top level:

- OpenCase: `opencase/round2/pages/Search-Citizen.md`. Menu Search, citizen
  search by number, name or address, then a citizen card (name, masked CPR
  with reveal, address, phone, email), tabs Cases and Documents, and Create
  case prefilled with this citizen.
- Zaaksysteem: `xxllnc-zaken/round2/pages/ContactZoeken.md` (nav Contact
  zoeken, tabs Persoon, Organisatie, Medewerker, a result row opens the
  contact view) and `xxllnc-zaken/round2/pages/ContactBeeld-persoon.md` (the
  360 view: Gegevens, Zaken, Communicatie, Kaart, Relaties, Tijdlijn; Zaak
  aanmaken for this person; Contactmoment).
- Dossiq baseline: no contact index, a dead Contacts tab
  (`_round2/dossiq-baseline/case-detail-anatomy.md` tab 9).

`tier-b-and-sibling.md` section 1 recommended waiting for B01 in OpenRegister
before spending the entry. D9 overrules that: the entry is spent now, over
the register sets dossiq already carries, and B01 stays the durable home.

## What changes

- One menu entry `Contacts` (icon `AccountGroupOutline`, order 25, after the
  My work group and before nothing else at the top level) in
  `src/manifest.json`, route `Contacts`. No group, no children.
- One index page `Contacts` of type `index` over `brpPerson` in register
  `dossiq`, with a `folderSidebar` of two folders: People and Organisations.
  The vocabulary today filters one schema by one field, so the Organisations
  folder needs a per-folder `schema` from nextcloud-vue; until then it ships
  in the manifest and is hidden (design D1).
- Two detail pages, `ContactDetail` over `brpPerson` and
  `OrganisationDetail` over `kvkCompany`, each with a data card, the
  contact's cases, the contact's contact moments, and the audit sidebar.
- Header actions on both: New case for this contact (`open-form` on `case`
  with `requester` prefilled) and Log contact (`open-form` on `contactmoment`
  with `contact` prefilled).
- Property `contactmoment.contact`: the person or organisation the moment is
  about, the same shape as `case.requester`.
- The initiator card on `CaseDetail` and the Requester column on `Cases`
  link to the contact page instead of the raw register row.
- **BREAKING** for nothing: no existing page moves, no property changes
  meaning. `customerContact` in `dossiq_register.json` stays as it is.

## Interim and durable route

Three pieces belong elsewhere. A folder that carries its own schema and
columns (nextcloud-vue, shares the seam of B10): until then the Contacts
index lists people, and organisations are reached from a case. A column that
links to a route chosen by a sibling field (nextcloud-vue): until then the
Requester column is text and the initiator card carries the link. The contact
360 with communication and timeline over the contacts leaf (OpenRegister,
B01): when it lands, `ContactDetail` adopts the leaf and keeps its cases
list. The KCC panel (B20) and the BRP and KvK subscriptions (B22) stay Tier B
and are only named as blocked tasks.

## Capabilities

### New capabilities

None.

### Modified capabilities

- `nav-dedup-and-grouping`: the navigation carries a Contacts domain at the
  top level, and the top-level count stays inside ADR-097.
- `initiator-display`: a contact page shows the person or organisation with
  their cases; the case's requester links there.
- `initiator-selection`: New case from a contact opens the case form with the
  requester filled in.
- `kcc-klantcontact-integratie`: a contact moment names its contact, and you
  log one from the contact page.

## Impact

- `src/manifest.json`: menu entry `Contacts`; pages `Contacts`,
  `ContactDetail`, `OrganisationDetail`; page `Cases` (Requester column);
  page `CaseDetail` (initiator card link).
- `src/menu-layout.json`: no relocation; a unit test pins that `Contacts`
  is not relocated or removed.
- `lib/Settings/register.d/40-kcc-werkplek.json` and
  `lib/Settings/dossiq_mock_register.json`: `contactmoment.contact`.
- E2E: `tests/e2e/contacts-domain.spec.ts` (new);
  `tests/e2e/navigation.spec.ts` and
  `tests/e2e/spec-coverage/work-navigation.spec.ts` (top-level entries).
- No PHP. No custom page: the custom-page count stays at baseline (ADR-100).
