> **Interlock note (2026-07-05, added by `external-integrations-test-environments`):** this change
> owns the register-side (schemas + fictitious seed data + initiator UI). The live BRP/KvK adapters
> flagged under Risks below are owned by `external-integrations-test-environments` — do not add
> real-API work here. Interlocks: (i) align the 10 seed persons/companies with the official fixture
> sets (BRP `personen-mock` `test-data.json` personas; KvK's published fictitious companies, e.g.
> KVK 69599084 / 68750110 / 69599068 / 55344526) so demo data and contract fixtures are the same
> objects; (ii) initiator search falls back register → live adapter per that change's config tiers.
> Also coordinate the initiator fields with `semantic-case-intake` (requester semantic reference is
> canonical; initiator display fields are its projection — one write path).

## Why

When creating a zaak (case), the initiator ("indiener") must be linked to a real person or organization. Currently, cases have no structured initiator data connected to base registries like BRP (Basisregistratie Personen) or KVK (Kamer van Koophandel). Users cannot search or select an initiator from authoritative sources during case creation. This is a core GEMMA Zaakafhandel requirement -- every zaak must have a "rol: initiator" linked to a betrokkene (involved party). Without BRP/KVK integration, Procest cannot demonstrate realistic case handling workflows.

## What Changes

- **REQ-BRP-001**: Create a BRP person register schema in OpenRegister with fields for BSN, name, address, and birthdate
- **REQ-BRP-002**: Seed 10 test persons into the BRP register for development and demo purposes
- **REQ-KVK-001**: Create a KVK company register schema in OpenRegister with fields for KVK number, trade name, legal form, and address
- **REQ-KVK-002**: Seed 10 test companies into the KVK register for development and demo purposes
- **REQ-INIT-001**: Add initiator type selector to case creation UI (Person / Company / Contact)
- **REQ-INIT-002**: Implement cross-source search across BRP, KVK, and Nextcloud Contacts with unified results
- **REQ-INIT-003**: Store selected initiator reference on the case (type + source ID)
- **REQ-INIT-004**: Display initiator details on the case detail view

## Capabilities

### New Capabilities
- `brp-register`: BRP person register schema and test seed data in OpenRegister
- `kvk-register`: KVK company register schema and test seed data in OpenRegister
- `initiator-selection`: Unified initiator search and selection across BRP, KVK, and Nextcloud Contacts during case creation
- `initiator-display`: Initiator details shown on case detail view with link to source record

### Modified Capabilities
- `procest-case-creation`: CaseCreateDialog extended with initiator type selector and cross-source search
- `procest-case-detail`: Case info panel shows initiator name, type, and source details
- `procest-register`: Case schema extended with initiator reference fields (type, sourceId, sourceName)

## Standards

- **GEMMA Zaakafhandel**: Rol "initiator" is a required betrokkene on every zaak
- **ZGW ZRC**: Rol betrokkene model (natuurlijk persoon / niet-natuurlijk persoon)
- **Haal Centraal BRP Personen API**: Field naming conventions for person data (burgerservicenummer, naam, verblijfplaats, geboorte)
- **KVK Zoeken API**: Field naming conventions for company data (kvkNummer, handelsnaam, rechtsvorm, adres)

## Impact

- **OpenRegister**: New register JSON schemas for BRP persons and KVK companies with seed data in `components.objects`
- **Frontend**: `CaseCreateDialog.vue` -- add initiator type tabs and search component
- **Frontend**: `CaseDetail.vue` -- display initiator info section
- **Backend**: Case schema in `procest_register.json` -- add `initiatorType`, `initiatorSourceId`, `initiatorDisplayName` fields
- **Dependencies**: OpenRegister (register schemas and seed data), Nextcloud Contacts API (DAV/CardDAV search)

## Interlock (added by `external-integrations-test-environments`, 2026-07-06)

The live BRP/KvK adapters this change's Risks flag as "would require Haal Centraal and KVK API
credentials" ARE now implemented by `external-integrations-test-environments`
(`HaalCentraalBrpAdapter`, `KvkApiAdapter`, config-tier binding). The seed persons/companies here
double as that change's contract fixtures — the same objects
(`tests/fixtures/contracts/{brp,kvk}/`). Do not add live-adapter work here; extend it there.

## Risks

- BRP and KVK schemas are simplified test versions based on API field naming conventions, not actual registry connections. Real BRP/KVK integration would require Haal Centraal and KVK API credentials and compliance.
- Nextcloud Contacts search depends on the Contacts app being installed and populated.
- Cross-source search performance may need optimization if registers grow large.
