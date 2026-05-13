---
status: implemented
---
# Base Register Seed Data Specification

## Purpose

Define mock/test register JSON files for five Dutch base registrations (BRP, KVK, BAG, DSO, ORI) with realistic seed data that enables full-cycle testing and demos of Procest (case management) and Pipelinq (CRM) features without external API access. These registers supplement the existing `procest_register.json` and `pipelinq_register.json` by providing the government data layer that these apps query during citizen/business identification, case enrichment, address resolution, permit intake, and council information display.

**Relationship to existing specs**: This spec extends `openregister/openspec/specs/mock-registers/spec.md` (which defines BRP and KVK requirements) by adding BAG, DSO, and ORI registers, specifying cross-register relationships, and defining concrete seed data scenarios tied to Procest and Pipelinq test cases.

**Consuming specs**:
- Procest `case-dashboard-view` (REQ-CDV-05b): BRP-persoon and BAG-object as linked objects
- Procest `vth-module` (REQ-VTH-01): DSO vergunningaanvraag intake with BAG locatie
- Procest `zaak-intake-flow`: Betrokkene identification via BRP/KVK
- Procest `legesberekening`: BAG oppervlakte for fee calculation
- Pipelinq `klantbeeld-360`: BRP/KVK enrichment for 360-degree customer view
- Pipelinq `kcc-werkplek`: BSN/KVK citizen/business identification
- Pipelinq `prospect-discovery`: KVK data for prospect search and scoring

**Feature tier**: MVP (BRP + KVK + BAG), V1 (DSO + ORI)

---

## File Structure

```
openregister/lib/Settings/
  brp_register.json           -- BRP (persons)
  kvk_register.json           -- KVK (businesses)
  bag_register.json           -- BAG (addresses/buildings)
  dso_register.json           -- DSO (permits/environment)
  ori_register.json           -- ORI (council information)
```

Each file follows the OpenRegister JSON format: OpenAPI 3.0 envelope with `x-openregister` metadata, `components.registers` (register definition), `components.schemas` (entity schemas), and `components.objects` (seed data). The repair step (`InitializeSettings`) loads each file via `SettingsService::loadConfiguration()`.

---

## ADDED Requirements
### Requirement: REQ-SEED-001 — BRP Register (Basisregistratie Personen)

The system MUST provide a `brp_register.json` file containing a BRP register with an `ingeschrevenPersoon` schema and at least 25 fictional person records.

**Feature tier**: MVP

##### Register Definition

| Field | Value |
|-------|-------|
| slug | `brp` |
| title | `BRP (Basisregistratie Personen)` |
| version | `1.0.0` |
| description | `Mock BRP register for development and testing. Contains fictional persons aligned with the Haal Centraal BRP Personen Bevragen API v2 response structure. Authority: RVIG (Rijksdienst voor Identiteitsgegevens).` |
| tablePrefix | (empty) |
| folder | `Open Registers/BRP` |
| schemas | `["ingeschrevenPersoon"]` |

##### Schema: `ingeschrevenPersoon`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `burgerservicenummer` | string (9 digits) | yes | no | BSN, MUST pass 11-proef validation | `"999993653"` |
| `voornamen` | string | yes | no | First names | `"Jan Albert"` |
| `voorletters` | string | no | no | Initials | `"J.A."` |
| `voorvoegsel` | string | no | no | Name prefix (tussenvoegsel) | `"de"` |
| `geslachtsnaam` | string | yes | yes | Family name | `"Vries"` |
| `aanhef` | string | no | no | Form of address | `"De heer"` |
| `geslachtsaanduiding` | string (enum) | yes | yes | Gender: `man`, `vrouw`, `onbekend` | `"man"` |
| `geboortedatum` | string (date) | yes | no | Date of birth (YYYY-MM-DD) | `"1985-03-15"` |
| `geboorteplaats` | string | no | no | Place of birth | `"Amsterdam"` |
| `geboorteland` | string | no | no | Country of birth (code table) | `"Nederland"` |
| `overlijdensdatum` | string (date) | no | no | Date of death (null if alive) | `null` |
| `verblijfplaatsStraat` | string | no | no | Street name | `"Keizersgracht"` |
| `verblijfplaatsHuisnummer` | integer | no | no | House number | `100` |
| `verblijfplaatsHuisletter` | string | no | no | House letter | `"A"` |
| `verblijfplaatsHuisnummertoevoeging` | string | no | no | House number suffix | `"bis"` |
| `verblijfplaatsPostcode` | string | no | no | Postal code (####XX) | `"1015AA"` |
| `verblijfplaatsWoonplaats` | string | no | yes | City | `"Amsterdam"` |
| `verblijfplaatsGemeente` | string | no | yes | Municipality of registration | `"Amsterdam"` |
| `nationaliteit` | string | no | yes | Nationality | `"Nederlandse"` |
| `burgerlijkeStaat` | string (enum) | no | yes | Marital status: `ongehuwd`, `gehuwd`, `gescheiden`, `weduwe/weduwnaar`, `partnerschap` | `"gehuwd"` |
| `partnerBsn` | string | no | no | BSN of partner (cross-ref within register) | `"999990019"` |
| `partnerNaam` | string | no | no | Full name of partner | `"Maria Bakker"` |
| `kinderen` | array of objects | no | no | Children `[{bsn, naam}]` | `[{"bsn":"999990020","naam":"Sophie de Vries"}]` |
| `ouders` | array of objects | no | no | Parents `[{bsn, naam}]` | `[{"bsn":"999990001","naam":"Pieter de Vries"}]` |
| `datumInschrijving` | string (date) | no | no | Registration date in municipality | `"2010-06-01"` |

**Design notes**:
- The flat property structure (e.g., `verblijfplaatsStraat` instead of nested `verblijfplaats.straat`) matches how OpenRegister stores object properties in the JSON column. Nested objects can be used but flat is simpler for faceting and search.
- The `partner`, `kinderen`, and `ouders` references use BSN strings that can be resolved within the same register, enabling cross-referencing without requiring UUID joins.

#### Scenario: BSN 11-proef validation

- **GIVEN** a seed person with `burgerservicenummer` value `"999993653"`
- **WHEN** the weighted checksum is calculated: `(9*9 + 9*8 + 9*7 + 9*6 + 9*5 + 3*4 + 6*3 + 5*2 - 3*1)`
- **THEN** the result MUST be divisible by 11
- **AND** all 25+ seed BSNs MUST pass the 11-proef
- **AND** all BSNs MUST start with `9999` (the known-fictional BSN range used by RVIG for testing)

#### Scenario: Family unit consistency

- **GIVEN** the seed data contains the De Vries family:
  - Jan Albert de Vries (BSN 999993653, born 1985-03-15, man, gehuwd)
  - Maria Bakker-de Vries (BSN 999990019, born 1987-11-22, vrouw, gehuwd)
  - Sophie de Vries (BSN 999990020, born 2015-06-10, vrouw, ongehuwd)
  - Thomas de Vries (BSN 999990021, born 2018-09-03, man, ongehuwd)
- **THEN** Jan's `partnerBsn` MUST equal Maria's BSN and vice versa
- **AND** Jan's `kinderen` MUST list Sophie and Thomas
- **AND** Sophie's `ouders` MUST list Jan and Maria
- **AND** all four MUST share the same `verblijfplaatsStraat`, `verblijfplaatsHuisnummer`, `verblijfplaatsPostcode`

#### Scenario: Geographic distribution

- **GIVEN** the 25+ seed persons
- **THEN** persons MUST be distributed across at least 5 municipalities: Amsterdam, Utrecht, Rotterdam, Den Haag, Tilburg
- **AND** postcodes MUST be realistic for the specified city (e.g., Amsterdam: 10xx, Utrecht: 35xx, Rotterdam: 30xx)

#### Scenario: Demographic diversity

- **GIVEN** the seed data
- **THEN** the following scenarios MUST be covered:
  - At least 3 married couples with children (family units)
  - At least 2 single persons (ongehuwd, no partner)
  - At least 1 divorced person (gescheiden)
  - At least 1 deceased person (overlijdensdatum set)
  - At least 1 person with non-Dutch nationality
  - At least 1 person with registered partnership (partnerschap)
  - Ages ranging from minors (under 18) to elderly (over 75)

#### Scenario: BRP person usable as case initiator

- **GIVEN** BRP person "Petra Jansen" (BSN 999990027)
- **WHEN** a Procest case of type "Omgevingsvergunning" is created
- **THEN** the person MUST be linkable as case initiator (betrokkene with role "Aanvrager")
- **AND** the person's BSN, naam, and verblijfplaats MUST be displayable in the case participants panel
- **AND** the person's address MUST resolve to a valid BAG nummeraanduiding

##### Seed Data Requirements Summary

| Scenario | Min Records | Purpose |
|----------|-------------|---------|
| Family with 2 children (De Vries) | 4 | Procest zaak-betrokkene linking, Pipelinq klantbeeld family view |
| Family with 1 child (Bakker) | 3 | Second family for cross-case testing |
| Family with 3 children (Jansen) | 5 | Large family, multi-child scenarios |
| Single persons | 3 | Pipelinq client creation from BRP |
| Divorced person + ex-partner | 2 | Burgerlijke staat edge case |
| Elderly couple | 2 | Age range coverage |
| Deceased person | 1 | Overlijden edge case |
| Non-Dutch nationals | 2 | Nationality filter testing |
| Registered partnership | 2 | Partnerschap scenario |
| Business owner (also in KVK) | 1 | Cross-register: BRP person = KVK eigenaar |
| **Total minimum** | **25** | |

---

### Requirement: REQ-SEED-002 — KVK Register (Kamer van Koophandel)

The system MUST provide a `kvk_register.json` file containing a KVK register with a `maatschappelijkeActiviteit` schema and at least 15 fictional business records.

**Feature tier**: MVP

##### Register Definition

| Field | Value |
|-------|-------|
| slug | `kvk` |
| title | `KVK (Handelsregister)` |
| version | `1.0.0` |
| description | `Mock KVK register for development and testing. Contains fictional businesses aligned with the KVK Handelsregister API (Basisprofiel/Vestigingsprofiel) response structure. Authority: Kamer van Koophandel.` |
| tablePrefix | (empty) |
| folder | `Open Registers/KVK` |
| schemas | `["maatschappelijkeActiviteit", "vestiging"]` |

##### Schema: `maatschappelijkeActiviteit`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `kvkNummer` | string (8 digits) | yes | no | KVK registration number | `"90001234"` |
| `handelsnaam` | string | yes | yes | Primary trade name | `"Bakkerij De Vries B.V."` |
| `handelsnamen` | array of strings | no | no | All trade names | `["Bakkerij De Vries","De Vries Patisserie"]` |
| `rechtsvorm` | string | yes | yes | Legal form display name | `"Besloten Vennootschap"` |
| `rechtsvormCode` | string | yes | yes | Legal form code: `BV`, `NV`, `Eenmanszaak`, `Stichting`, `VOF`, `CV`, `Cooperatie`, `Vereniging`, `Maatschap` | `"BV"` |
| `rsin` | string (9 digits) | no | no | RSIN (Rechtspersonen en Samenwerkingsverbanden Identificatienummer) | `"123456789"` |
| `vestigingsadresStraat` | string | no | no | Street name of main establishment | `"Prinsengracht"` |
| `vestigingsadresHuisnummer` | integer | no | no | House number | `200` |
| `vestigingsadresPostcode` | string | no | no | Postal code (####XX) | `"1016GS"` |
| `vestigingsadresPlaats` | string | no | yes | City | `"Amsterdam"` |
| `vestigingsadresProvincie` | string | no | yes | Province | `"Noord-Holland"` |
| `sbiHoofdactiviteit` | string | yes | yes | Primary SBI code | `"1071"` |
| `sbiHoofdactiviteitOmschrijving` | string | no | yes | Primary SBI description | `"Vervaardiging van brood en banket"` |
| `sbiActiviteiten` | array of objects | no | no | All SBI activities `[{sbiCode, omschrijving, isHoofdactiviteit}]` | see below |
| `aantalWerkzamePersonen` | integer | no | no | Number of employees | `25` |
| `datumOprichting` | string (date) | no | no | Date of establishment | `"2005-09-12"` |
| `datumUitschrijving` | string (date) | no | no | Date of deregistration (null if active) | `null` |
| `actief` | boolean | yes | yes | Whether the business is active | `true` |
| `eigenaarNaam` | string | no | no | Owner name (links to BRP for eenmanszaak) | `"J.A. de Vries"` |
| `eigenaarBsn` | string | no | no | Owner BSN (cross-ref to BRP, for eenmanszaak/VOF) | `"999993653"` |
| `website` | string (uri) | no | no | Company website | `"https://www.devries-bakkerij.nl"` |
| `emailadres` | string (email) | no | no | Contact email | `"info@devries-bakkerij.nl"` |
| `telefoonnummer` | string | no | no | Contact phone | `"+31 20 1234567"` |

##### Schema: `vestiging`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `vestigingsnummer` | string (12 digits) | yes | no | Vestiging registration number | `"000012345678"` |
| `kvkNummer` | string (8 digits) | yes | no | Parent KVK number (cross-ref) | `"90001234"` |
| `handelsnaam` | string | yes | yes | Trade name of this vestiging | `"Bakkerij De Vries - Filiaal Zuid"` |
| `type` | string (enum) | yes | yes | `hoofdvestiging` or `nevenvestiging` | `"nevenvestiging"` |
| `adresStraat` | string | no | no | Street name | `"Beethovenstraat"` |
| `adresHuisnummer` | integer | no | no | House number | `42` |
| `adresPostcode` | string | no | no | Postal code | `"1077JJ"` |
| `adresPlaats` | string | no | yes | City | `"Amsterdam"` |
| `sbiActiviteiten` | array of objects | no | no | SBI activities at this location | see parent schema |
| `aantalWerkzamePersonen` | integer | no | no | Employees at this location | `8` |
| `actief` | boolean | yes | yes | Whether the vestiging is active | `true` |

#### Scenario: Legal form diversity

- **GIVEN** the 15+ seed businesses
- **THEN** the following legal forms MUST be represented:
  - BV (Besloten Vennootschap): at least 4 records
  - Eenmanszaak: at least 3 records (with `eigenaarBsn` linking to BRP persons)
  - Stichting: at least 2 records
  - VOF (Vennootschap onder Firma): at least 1 record
  - NV (Naamloze Vennootschap): at least 1 record
  - Vereniging: at least 1 record
- **AND** at least 1 business MUST have `actief: false` with `datumUitschrijving` set

#### Scenario: SBI code diversity

- **GIVEN** the seed businesses
- **THEN** businesses MUST cover at least 8 different SBI top-level sections:
  - A (Landbouw): e.g., `"0111"` Akkerbouw
  - C (Industrie): e.g., `"1071"` Brood en banket
  - F (Bouw): e.g., `"4120"` Algemene burgerlijke en utiliteitsbouw
  - G (Handel): e.g., `"4711"` Supermarkten
  - I (Horeca): e.g., `"5610"` Restaurants
  - J (Informatie/communicatie): e.g., `"6201"` Ontwikkelen en produceren van software
  - M (Advisering): e.g., `"6920"` Accountancy en belastingadvies
  - Q (Zorg): e.g., `"8610"` Ziekenhuizen

#### Scenario: Cross-register BRP linkage

- **GIVEN** BRP person "Jan Albert de Vries" (BSN 999993653) is a business owner
- **WHEN** the KVK seed data includes an eenmanszaak "De Vries Consultancy"
- **THEN** `eigenaarBsn` MUST equal `"999993653"`
- **AND** `eigenaarNaam` MUST equal `"J.A. de Vries"`
- **AND** `vestigingsadresStraat` + `vestigingsadresPostcode` SHOULD match Jan's BRP `verblijfplaatsStraat` + `verblijfplaatsPostcode` (common for eenmanszaak)

#### Scenario: Business with multiple vestigingen

- **GIVEN** seed business "Bakkerij De Vries B.V." (KVK 90001234)
- **THEN** at least 2 vestiging records MUST exist:
  - Hoofdvestiging: Prinsengracht 200, Amsterdam
  - Nevenvestiging: Beethovenstraat 42, Amsterdam
- **AND** both vestigingen MUST reference the same `kvkNummer`

#### Scenario: Business usable as case betrokkene

- **GIVEN** a KVK business "Architectenbureau Van Dam B.V." (KVK 90005678)
- **WHEN** a Procest case of type "Omgevingsvergunning" is created
- **THEN** the business MUST be linkable as a case participant (betrokkene with role "Gemachtigde")
- **AND** the business's KVK number, handelsnaam, and vestigingsadres MUST be displayable

##### Seed Data Requirements Summary

| Scenario | Min Records | Purpose |
|----------|-------------|---------|
| BV businesses (various sectors) | 4 | Pipelinq client management, prospect discovery |
| Eenmanszaak (with BRP link) | 3 | Cross-register testing, KCC identification |
| Stichtingen | 2 | Non-profit sector testing |
| VOF | 1 | Multi-owner business |
| NV | 1 | Large corporation scenario |
| Vereniging | 1 | Community organization |
| Inactive business | 1 | Deregistered edge case |
| Multi-vestiging business | 1 (+2 vestigingen) | Vestiging search in Pipelinq |
| IT/software company | 1 | Pipelinq SBI filter testing |
| **Total minimum maatschappelijkeActiviteit** | **15** | |
| **Total minimum vestiging** | **18** | (15 hoofd + 3 neven) |

---

### Requirement: REQ-SEED-003 — BAG Register (Basisregistratie Adressen en Gebouwen)

The system MUST provide a `bag_register.json` file containing a BAG register with schemas for `nummeraanduiding`, `openbareRuimte`, `woonplaats`, `verblijfsobject`, and `pand`, with seed data that matches the addresses used in BRP and KVK seed data.

**Feature tier**: MVP

##### Register Definition

| Field | Value |
|-------|-------|
| slug | `bag` |
| title | `BAG (Basisregistratie Adressen en Gebouwen)` |
| version | `1.0.0` |
| description | `Mock BAG register for development and testing. Contains fictional addresses and buildings aligned with the BAG API Individuele Bevragingen v2 response structure. Authority: Kadaster.` |
| tablePrefix | (empty) |
| folder | `Open Registers/BAG` |
| schemas | `["nummeraanduiding", "openbareRuimte", "woonplaats", "verblijfsobject", "pand"]` |

##### Schema: `nummeraanduiding`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `identificatie` | string (16 digits) | yes | no | BAG object ID | `"0363200000000001"` |
| `huisnummer` | integer | yes | no | House number | `100` |
| `huisletter` | string | no | no | House letter | `"A"` |
| `huisnummertoevoeging` | string | no | no | House number suffix | `"bis"` |
| `postcode` | string | yes | yes | Postal code (####XX) | `"1015AA"` |
| `status` | string (enum) | yes | yes | `naamgeving uitgegeven`, `naamgeving ingetrokken` | `"naamgeving uitgegeven"` |
| `typeAdresseerbaarObject` | string (enum) | no | yes | `Verblijfsobject`, `Standplaats`, `Ligplaats` | `"Verblijfsobject"` |
| `openbareRuimteNaam` | string | yes | no | Street name (denormalized for search) | `"Keizersgracht"` |
| `woonplaatsNaam` | string | yes | yes | City name (denormalized for search) | `"Amsterdam"` |
| `openbareRuimteId` | string | no | no | Reference to openbareRuimte | `"0363300000000001"` |
| `verblijfsobjectId` | string | no | no | Reference to verblijfsobject | `"0363010000000001"` |

##### Schema: `openbareRuimte`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `identificatie` | string (16 digits) | yes | no | BAG object ID | `"0363300000000001"` |
| `naam` | string | yes | yes | Street/public space name | `"Keizersgracht"` |
| `type` | string (enum) | yes | yes | `Weg`, `Water`, `Spoorbaan`, `Terrein`, `Kunstwerk`, `Landschappelijk gebied`, `Administratief gebied` | `"Weg"` |
| `status` | string (enum) | yes | yes | `naamgeving uitgegeven`, `naamgeving ingetrokken` | `"naamgeving uitgegeven"` |
| `woonplaatsNaam` | string | yes | yes | City name | `"Amsterdam"` |
| `woonplaatsId` | string | no | no | Reference to woonplaats | `"3594"` |

##### Schema: `woonplaats`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `identificatie` | string (4 digits) | yes | no | Woonplaats code | `"3594"` |
| `naam` | string | yes | yes | City/town name | `"Amsterdam"` |
| `status` | string (enum) | yes | yes | `woonplaats aangewezen`, `woonplaats ingetrokken` | `"woonplaats aangewezen"` |
| `gemeente` | string | no | yes | Municipality name | `"Amsterdam"` |
| `provincie` | string | no | yes | Province name | `"Noord-Holland"` |

##### Schema: `verblijfsobject`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `identificatie` | string (16 digits) | yes | no | BAG object ID | `"0363010000000001"` |
| `status` | string (enum) | yes | yes | `verblijfsobject gevormd`, `verblijfsobject in gebruik (niet ingemeten)`, `verblijfsobject in gebruik`, `verblijfsobject ingetrokken`, `verblijfsobject buiten gebruik` | `"verblijfsobject in gebruik"` |
| `gebruiksdoel` | string (enum) | yes | yes | `woonfunctie`, `bijeenkomstfunctie`, `celfunctie`, `gezondheidszorgfunctie`, `industriefunctie`, `kantoorfunctie`, `logiesfunctie`, `onderwijsfunctie`, `sportfunctie`, `winkelfunctie`, `overige gebruiksfunctie` | `"woonfunctie"` |
| `gebruiksdoelen` | array of strings | no | no | Multiple use purposes | `["woonfunctie"]` |
| `oppervlakte` | integer | yes | no | Usable surface area in m2 | `120` |
| `pandId` | string | no | no | Reference to pand | `"0363100000000001"` |
| `nummeraanduidingId` | string | no | no | Reference to main nummeraanduiding | `"0363200000000001"` |
| `bouwjaar` | integer | no | no | Construction year (from pand, denormalized) | `1895` |

##### Schema: `pand`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `identificatie` | string (16 digits) | yes | no | BAG object ID | `"0363100000000001"` |
| `status` | string (enum) | yes | yes | `bouwvergunning verleend`, `bouw gestart`, `pand in gebruik (niet ingemeten)`, `pand in gebruik`, `sloopvergunning verleend`, `pand gesloopt`, `pand buiten gebruik`, `niet gerealiseerd pand`, `verbouwing pand` | `"pand in gebruik"` |
| `oorspronkelijkBouwjaar` | integer | yes | no | Original construction year | `1895` |
| `oppervlakte` | integer | no | no | Gross surface area in m2 | `450` |

#### Scenario: BAG addresses match BRP persons

- **GIVEN** BRP person Jan de Vries lives at Keizersgracht 100A, 1015AA Amsterdam
- **THEN** the BAG MUST contain:
  - A `woonplaats` record for Amsterdam (identificatie `"3594"`)
  - An `openbareRuimte` record for Keizersgracht in Amsterdam
  - A `nummeraanduiding` with huisnummer 100, huisletter A, postcode 1015AA
  - A `verblijfsobject` with `gebruiksdoel` = `"woonfunctie"`, linked to a `pand`
  - A `pand` with `oorspronkelijkBouwjaar` and `status` = `"pand in gebruik"`

#### Scenario: BAG addresses match KVK businesses

- **GIVEN** KVK business "Bakkerij De Vries B.V." at Prinsengracht 200, 1016GS Amsterdam
- **THEN** the BAG MUST contain corresponding `nummeraanduiding`, `openbareRuimte`, `verblijfsobject` (gebruiksdoel `"winkelfunctie"`), and `pand` records
- **AND** the BAG address components MUST be consistent: `nummeraanduiding.openbareRuimteNaam` = the openbareRuimte name, `nummeraanduiding.woonplaatsNaam` = the woonplaats name

#### Scenario: Address for DSO vergunningaanvraag

- **GIVEN** DSO vergunningaanvraag for a building project at Herengracht 300, 1016CE Amsterdam
- **THEN** the BAG MUST contain the corresponding address records
- **AND** the `pand` SHOULD have `status` = `"verbouwing pand"` to represent an ongoing building project
- **AND** the `verblijfsobject` MUST have `oppervlakte` set (used in legesberekening)

#### Scenario: Multiple residents at one address

- **GIVEN** the Jansen family (5 persons) lives at Maliebaan 50, 3581CS Utrecht
- **THEN** ONE `nummeraanduiding` record MUST exist for that address
- **AND** the `verblijfsobject` `gebruiksdoel` MUST be `"woonfunctie"`
- **AND** all 5 BRP persons MUST reference the same address (postcode + huisnummer + straat + woonplaats)

#### Scenario: Oppervlakte for legesberekening

- **GIVEN** a Procest case of type "Omgevingsvergunning" at Herengracht 300
- **WHEN** the case references a BAG verblijfsobject
- **THEN** the `oppervlakte` field MUST be a positive integer representing usable floor area in m2
- **AND** the value MUST be usable in the legesberekening formula (fee = base + oppervlakte * rate)

##### Seed Data Requirements Summary

| Entity | Min Records | Notes |
|--------|-------------|-------|
| woonplaats | 5 | Amsterdam, Utrecht, Rotterdam, Den Haag, Tilburg |
| openbareRuimte | 20 | Streets matching BRP/KVK addresses |
| nummeraanduiding | 35 | All BRP + KVK addresses (deduplicated) |
| verblijfsobject | 35 | One per nummeraanduiding |
| pand | 30 | Some shared (apartment buildings) |

---

### Requirement: REQ-SEED-004 — DSO Register (Digitaal Stelsel Omgevingswet)

The system MUST provide a `dso_register.json` file containing a DSO register with schemas for `vergunningaanvraag` and `activiteit`, with seed data representing permit applications in the Omgevingswet domain.

**Feature tier**: V1

##### Register Definition

| Field | Value |
|-------|-------|
| slug | `dso` |
| title | `DSO (Digitaal Stelsel Omgevingswet)` |
| version | `1.0.0` |
| description | `Mock DSO register for development and testing. Contains fictional permit applications aligned with the STAM/IMAM (Standaard Aanvragen en Meldingen / Informatiemodel Aanvragen en Meldingen) standard. Authority: Ministerie van BZK via IPLO.` |
| tablePrefix | (empty) |
| folder | `Open Registers/DSO` |
| schemas | `["vergunningaanvraag", "activiteit"]` |

##### Schema: `vergunningaanvraag`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `zaaknummer` | string | yes | no | DSO case reference number | `"OLO-2026-00001"` |
| `aanvraagdatum` | string (date) | yes | no | Date of application | `"2026-01-15"` |
| `procedureType` | string (enum) | yes | yes | `regulier` (8 wk), `uitgebreid` (26 wk) | `"regulier"` |
| `omschrijving` | string | yes | no | Description of the project | `"Verbouwing woonhuis tot kantoor"` |
| `locatieAdres` | string | no | no | Address of the project (display) | `"Herengracht 300, 1016CE Amsterdam"` |
| `locatiePostcode` | string | no | yes | Postcode of the project location | `"1016CE"` |
| `locatiePlaats` | string | no | yes | City of the project location | `"Amsterdam"` |
| `locatieBagId` | string | no | no | BAG nummeraanduiding identificatie (cross-ref) | `"0363200000000010"` |
| `locatieKadastraalPerceel` | string | no | no | Cadastral parcel identifier | `"ASD04-F-1234"` |
| `initiatiefnemerNaam` | string | yes | no | Applicant name | `"Petra Jansen"` |
| `initiatiefnemerBsn` | string | no | no | Applicant BSN (cross-ref to BRP) | `"999990027"` |
| `initiatiefnemerKvk` | string | no | no | Applicant KVK number (cross-ref, if business) | `"90001234"` |
| `gemachtigdeNaam` | string | no | no | Authorized representative name | `"Architectenbureau Van Dam B.V."` |
| `bouwkosten` | number | no | no | Estimated construction costs in EUR | `180000` |
| `oppervlakte` | integer | no | no | Area in m2 | `250` |
| `activiteiten` | array of strings | no | no | List of activities from the application | `["Bouwen","Kappen","Uitrit aanleggen"]` |
| `status` | string (enum) | yes | yes | `ingediend`, `ontvankelijk`, `in_behandeling`, `besluit_genomen`, `verleend`, `geweigerd`, `ingetrokken`, `buiten_behandeling` | `"ingediend"` |
| `besluitdatum` | string (date) | no | no | Date of decision | `null` |
| `resultaat` | string (enum) | no | yes | `verleend`, `geweigerd`, `deels_verleend` | `null` |

##### Schema: `activiteit`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `naam` | string | yes | yes | Activity name from Omgevingswet | `"Bouwen van een bouwwerk"` |
| `code` | string | yes | no | DSO activity code | `"BOUWEN-001"` |
| `categorie` | string | yes | yes | `bouwactiviteit`, `milieubelastende activiteit`, `omgevingsplanactiviteit`, `Natura 2000-activiteit`, `ontgrondingsactiviteit` | `"bouwactiviteit"` |
| `regelgevingType` | string | no | yes | `vergunningplicht`, `meldingsplicht`, `informatieplicht` | `"vergunningplicht"` |
| `bevoegdGezag` | string | no | yes | Competent authority type | `"gemeente"` |
| `omschrijving` | string | no | no | Detailed description of the activity | `"Het bouwen van een bouwwerk waarvoor een omgevingsvergunning vereist is"` |

#### Scenario: Bouwvergunning linked to BAG

- **GIVEN** a vergunningaanvraag for "Verbouwing woonhuis" at Herengracht 300
- **THEN** `locatieBagId` MUST reference a valid BAG `nummeraanduiding` in the BAG seed data
- **AND** the `locatieAdres` MUST match the BAG address components
- **AND** `initiatiefnemerBsn` MUST reference a valid BRP person

#### Scenario: Multiple activities in one application

- **GIVEN** a vergunningaanvraag with `activiteiten: ["Bouwen","Kappen","Uitrit aanleggen"]`
- **THEN** 3 corresponding `activiteit` records MUST exist in the DSO register
- **AND** the `vergunningaanvraag` links to these activities by name

#### Scenario: Various permit types

- **GIVEN** the seed data
- **THEN** the following application types MUST be represented:
  - Bouwvergunning (bouwen van een bouwwerk): reguliere procedure
  - Milieuvergunning (milieubelastende activiteit): uitgebreide procedure
  - Kapvergunning (vellen van houtopstand): reguliere procedure
  - Omgevingsplanactiviteit (afwijken van omgevingsplan): reguliere procedure
  - Combined application (samenloop): multiple activities in one aanvraag
- **AND** at least 1 application MUST have `status` = `"verleend"` with `besluitdatum` set
- **AND** at least 1 application MUST have `status` = `"geweigerd"`

#### Scenario: DSO intake to Procest case mapping

- **GIVEN** a DSO vergunningaanvraag with `zaaknummer = "OLO-2026-00001"`
- **WHEN** the system maps this to a Procest case
- **THEN** the case MUST reference the DSO zaaknummer as external identifier
- **AND** the case type MUST map from the DSO procedureType (regulier -> "Omgevingsvergunning regulier")
- **AND** the case deadline MUST be calculated from the procedureType (regulier = 8 weeks, uitgebreid = 26 weeks)

##### Seed Data Requirements Summary

| Entity | Min Records | Notes |
|--------|-------------|-------|
| vergunningaanvraag | 8 | Various types, statuses, and locations |
| activiteit | 12 | Standard Omgevingswet activities |

---

### Requirement: REQ-SEED-005 — ORI Register (Open Raadsinformatie)

The system MUST provide an `ori_register.json` file containing an ORI register with schemas for council meetings, agenda items, motions, votes, council members, and factions, with seed data representing a fictional municipal council.

**Feature tier**: V1

##### Register Definition

| Field | Value |
|-------|-------|
| slug | `ori` |
| title | `ORI (Open Raadsinformatie)` |
| version | `1.0.0` |
| description | `Mock ORI register for development and testing. Contains fictional council proceedings aligned with the Popolo data standard and Open State Foundation ORI API conventions. Authority: gemeenteraad (municipal council).` |
| tablePrefix | (empty) |
| folder | `Open Registers/ORI` |
| schemas | `["vergadering", "agendapunt", "document", "motie", "amendement", "stemming", "raadslid", "fractie"]` |

##### Schema: `vergadering`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `naam` | string | yes | no | Meeting name | `"Raadsvergadering 15 januari 2026"` |
| `type` | string (enum) | yes | yes | `raadsvergadering`, `commissievergadering`, `informatieavond`, `presidium` | `"raadsvergadering"` |
| `commissie` | string | no | yes | Committee name (if commissievergadering) | `"Commissie Ruimte en Wonen"` |
| `startDatum` | string (date-time) | yes | no | Start date/time | `"2026-01-15T19:30:00+01:00"` |
| `eindDatum` | string (date-time) | no | no | End date/time | `"2026-01-15T23:15:00+01:00"` |
| `locatie` | string | no | no | Physical location | `"Raadzaal, Stadhuis"` |
| `status` | string (enum) | yes | yes | `gepland`, `bevestigd`, `afgelopen`, `geannuleerd` | `"afgelopen"` |
| `voorzitter` | string | no | no | Chair name | `"Burgemeester Van den Berg"` |

##### Schema: `agendapunt`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `titel` | string | yes | no | Agenda item title | `"Vaststelling bestemmingsplan Centrum-Oost"` |
| `vergaderingId` | string (uuid) | yes | no | Reference to vergadering | (uuid) |
| `volgorde` | integer | yes | no | Order on agenda | `3` |
| `type` | string (enum) | yes | yes | `bespreekstuk`, `hamerstuk`, `informerend`, `procedureel` | `"bespreekstuk"` |
| `portefeuille` | string | no | yes | Portfolio/department | `"Ruimtelijke Ordening"` |
| `resultaat` | string | no | yes | Outcome | `"Aangenomen"` |

##### Schema: `document`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `titel` | string | yes | no | Document title | `"Raadsvoorstel vaststelling bestemmingsplan"` |
| `type` | string (enum) | yes | yes | `raadsvoorstel`, `raadsbesluit`, `amendement`, `motie`, `brief`, `nota`, `verslag`, `bijlage` | `"raadsvoorstel"` |
| `agendapuntId` | string (uuid) | no | no | Reference to agendapunt | (uuid) |
| `datum` | string (date) | yes | no | Document date | `"2026-01-08"` |
| `bestandsnaam` | string | no | no | File name | `"RV-2026-001-bestemmingsplan.pdf"` |
| `samenvatting` | string | no | no | Summary | `"Voorstel tot vaststelling van het bestemmingsplan Centrum-Oost"` |

##### Schema: `motie`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `titel` | string | yes | no | Motion title | `"Motie vreemd: Meer groen in de binnenstad"` |
| `agendapuntId` | string (uuid) | no | no | Reference to agenda item (null for motie vreemd) | (uuid or null) |
| `indieners` | array of strings | yes | no | Submitting faction names | `["GroenLinks","D66"]` |
| `dictum` | string | yes | no | The actual request/instruction | `"Verzoekt het college om binnen 6 maanden een groenplan op te stellen voor de binnenstad"` |
| `datumIndiening` | string (date) | yes | no | Date of submission | `"2026-01-15"` |
| `status` | string (enum) | yes | yes | `ingediend`, `aangenomen`, `verworpen`, `ingetrokken`, `aangehouden` | `"aangenomen"` |
| `voorStemmen` | integer | no | no | Votes in favor | `22` |
| `tegenStemmen` | integer | no | no | Votes against | `15` |

##### Schema: `amendement`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `titel` | string | yes | no | Amendment title | `"Amendement: Maximale bouwhoogte 25 meter"` |
| `agendapuntId` | string (uuid) | yes | no | Reference to agenda item | (uuid) |
| `indieners` | array of strings | yes | no | Submitting faction names | `["SP","PvdA"]` |
| `wijziging` | string | yes | no | Proposed change text | `"Wijzigt artikel 3.2: maximale bouwhoogte van 30 naar 25 meter"` |
| `toelichting` | string | no | no | Explanation | `"Om het historische straatbeeld te beschermen"` |
| `datumIndiening` | string (date) | yes | no | Date of submission | `"2026-01-15"` |
| `status` | string (enum) | yes | yes | `ingediend`, `aangenomen`, `verworpen`, `ingetrokken` | `"verworpen"` |
| `voorStemmen` | integer | no | no | Votes in favor | `14` |
| `tegenStemmen` | integer | no | no | Votes against | `23` |

##### Schema: `stemming`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `onderwerp` | string | yes | no | What is being voted on | `"Motie: Meer groen in de binnenstad"` |
| `type` | string (enum) | yes | yes | `motie`, `amendement`, `raadsvoorstel`, `benoeming` | `"motie"` |
| `vergaderingId` | string (uuid) | yes | no | Reference to vergadering | (uuid) |
| `datum` | string (date) | yes | no | Vote date | `"2026-01-15"` |
| `resultaat` | string (enum) | yes | yes | `aangenomen`, `verworpen` | `"aangenomen"` |
| `voorStemmen` | integer | yes | no | Votes in favor | `22` |
| `tegenStemmen` | integer | yes | no | Votes against | `15` |
| `onthouding` | integer | no | no | Abstentions | `0` |
| `stemmenPerFractie` | array of objects | no | no | `[{fractie, stem, aantalLeden}]` | see below |

##### Schema: `raadslid`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `naam` | string | yes | yes | Full name | `"Ahmed El Amrani"` |
| `fractie` | string | yes | yes | Faction name | `"GroenLinks"` |
| `functie` | string | no | yes | Role: `raadslid`, `fractievoorzitter`, `wethouder`, `burgemeester` | `"raadslid"` |
| `email` | string (email) | no | no | Council email | `"a.elamrani@gemeenteraad.nl"` |
| `actief` | boolean | yes | yes | Currently serving | `true` |
| `startdatum` | string (date) | no | no | Start of term | `"2022-03-30"` |
| `einddatum` | string (date) | no | no | End of term (null if current) | `null` |
| `portefeuilles` | array of strings | no | no | Portfolio areas | `["Duurzaamheid","Groen"]` |

##### Schema: `fractie`

| Property | Type | Required | Facetable | Description | Example |
|----------|------|----------|-----------|-------------|---------|
| `naam` | string | yes | yes | Faction/party name | `"GroenLinks"` |
| `afkorting` | string | no | yes | Abbreviation | `"GL"` |
| `aantalZetels` | integer | yes | no | Number of seats | `7` |
| `coalitie` | boolean | yes | yes | Part of the coalition | `true` |
| `fractievoorzitter` | string | no | no | Chair name | `"Ahmed El Amrani"` |

#### Scenario: Complete council composition

- **GIVEN** the seed data
- **THEN** at least 7 fracties MUST exist representing a realistic Dutch council composition:
  - VVD (6 zetels, coalitie)
  - GroenLinks (7 zetels, coalitie)
  - D66 (5 zetels, coalitie)
  - PvdA (4 zetels, oppositie)
  - CDA (3 zetels, oppositie)
  - SP (3 zetels, oppositie)
  - Lokaal Belang (2 zetels, oppositie)
- **AND** at least 30 raadslid records MUST exist (sum of all zetels)
- **AND** each raadslid MUST reference a valid fractie name

#### Scenario: Council meeting with full proceedings

- **GIVEN** a raadsvergadering "Raadsvergadering 15 januari 2026"
- **THEN** the meeting MUST have at least 8 agendapunten
- **AND** at least 2 moties MUST be linked (1 aangenomen, 1 verworpen)
- **AND** at least 1 amendement MUST be linked
- **AND** at least 3 stemmingen MUST be recorded with `stemmenPerFractie` data
- **AND** at least 5 documenten MUST be linked to various agendapunten

#### Scenario: Committee meeting

- **GIVEN** the seed data
- **THEN** at least 1 commissievergadering MUST exist (e.g., "Commissie Ruimte en Wonen")
- **AND** the committee meeting MUST have at least 3 agendapunten of type `bespreekstuk` or `informerend`

#### Scenario: Stemming with complete fractie breakdown

- **GIVEN** a stemming on "Motie: Meer groen in de binnenstad"
- **THEN** `stemmenPerFractie` MUST contain entries for all 7 fracties
- **AND** the sum of `aantalLeden` across fracties MUST equal the total council size (30)
- **AND** `voorStemmen` + `tegenStemmen` + `onthouding` MUST equal the total council size

##### Seed Data Requirements Summary

| Entity | Min Records | Notes |
|--------|-------------|-------|
| fractie | 7 | Realistic Dutch council composition |
| raadslid | 30 | All council members across factions |
| vergadering | 3 | 2 raadsvergaderingen + 1 commissie |
| agendapunt | 15 | Across all meetings |
| document | 20 | Raadsvoorstellen, besluiten, bijlagen |
| motie | 4 | Various statuses |
| amendement | 2 | Aangenomen + verworpen |
| stemming | 6 | With fractie-level detail |

---

### Requirement: REQ-SEED-006 — Cross-Register Relationship Integrity

All cross-register references between seed data MUST be consistent and resolvable.

**Feature tier**: MVP

#### Scenario: BRP persons live at BAG addresses

- **GIVEN** BRP person "Jan de Vries" with `verblijfplaatsStraat` = `"Keizersgracht"`, `verblijfplaatsHuisnummer` = `100`, `verblijfplaatsPostcode` = `"1015AA"`, `verblijfplaatsWoonplaats` = `"Amsterdam"`
- **THEN** the BAG register MUST contain:
  - A `nummeraanduiding` with matching `openbareRuimteNaam`, `huisnummer`, `postcode`, `woonplaatsNaam`
  - A `verblijfsobject` linked to that nummeraanduiding with `gebruiksdoel` = `"woonfunctie"`
- **AND** this mapping MUST hold for ALL BRP person addresses

#### Scenario: KVK businesses have BAG vestigingsadressen

- **GIVEN** KVK business "Bakkerij De Vries B.V." at Prinsengracht 200, 1016GS Amsterdam
- **THEN** the BAG register MUST contain a `nummeraanduiding` + `verblijfsobject` at that address
- **AND** the `verblijfsobject.gebruiksdoel` MUST be appropriate for the business type (e.g., `"winkelfunctie"` for a bakery, `"kantoorfunctie"` for a consultancy)

#### Scenario: DSO applications reference BAG and BRP

- **GIVEN** DSO vergunningaanvraag at Herengracht 300
- **THEN** `locatieBagId` MUST reference an existing BAG `nummeraanduiding.identificatie`
- **AND** `initiatiefnemerBsn` MUST reference an existing BRP `ingeschrevenPersoon.burgerservicenummer`

#### Scenario: Eenmanszaak owners link BRP to KVK

- **GIVEN** KVK eenmanszaak "De Vries Consultancy" with `eigenaarBsn` = `"999993653"`
- **THEN** BRP person with BSN `"999993653"` MUST exist
- **AND** the business `vestigingsadresStraat`/`vestigingsadresPostcode` SHOULD match the BRP person's `verblijfplaatsStraat`/`verblijfplaatsPostcode` (typical for eenmanszaak)

#### Scenario: Procest cases can reference all registers

- **GIVEN** a Procest case of type "Omgevingsvergunning" created from seed data
- **THEN** the case SHOULD be linkable to:
  - A BRP person as `betrokkene` (aanvrager) via BSN
  - A BAG address as `zaakobject` via nummeraanduiding ID
  - A DSO vergunningaanvraag as source via zaaknummer
  - An ORI agendapunt (optional, for politically sensitive cases)

#### Scenario: Pipelinq clients map to KVK

- **GIVEN** a Pipelinq client of type `"organization"` with a KVK number
- **THEN** the KVK number MUST match a `maatschappelijkeActiviteit.kvkNummer` in the KVK seed data
- **AND** the client `address` SHOULD match the KVK `vestigingsadresStraat` + `vestigingsadresPlaats`

---

### Requirement: REQ-SEED-007 — Seed Data Loading

The register JSON files MUST be loadable by the existing OpenRegister configuration mechanism.

**Feature tier**: MVP

#### Scenario: Load via CLI command

- **GIVEN** the `brp_register.json` file exists in `openregister/lib/Settings/`
- **WHEN** the admin runs `docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json`
- **THEN** the register, schemas, and seed objects MUST be created in OpenRegister
- **AND** seed objects MUST be created from the `components.objects` array in the file
- **AND** the command MUST output a summary of created entities

#### Scenario: Skip if already populated

- **GIVEN** the BRP register already contains person objects
- **WHEN** the load command runs again
- **THEN** existing data MUST NOT be duplicated
- **AND** the command MUST log that seeding was skipped

#### Scenario: Seed data uses @self references

- **GIVEN** seed objects in the JSON file use the `@self` pattern from opencatalogi
- **THEN** each seed object MUST include:
  ```json
  {
    "@self": {
      "register": "brp",
      "schema": "ingeschrevenPersoon",
      "slug": "jan-de-vries"
    },
    "burgerservicenummer": "999993653",
    "voornamen": "Jan Albert",
    ...
  }
  ```
- **AND** the `slug` MUST be unique within the schema
- **AND** the `register` and `schema` values MUST reference definitions in the same file

#### Scenario: Load via API

- **GIVEN** the `brp_register.json` file content
- **WHEN** the admin calls `POST /index.php/apps/openregister/api/registers/import` with the JSON content
- **THEN** the register, schemas, and seed objects MUST be created identically to the CLI method
- **AND** the API MUST return HTTP 200 with a summary of created entities

#### Scenario: Loading order independence

- **GIVEN** registers with cross-references (e.g., DSO referencing BAG)
- **WHEN** registers are loaded in any order
- **THEN** cross-register references MUST be stored as string values (not resolved UUIDs)
- **AND** applications MUST resolve references at query time via search by identifier

---

### Requirement: REQ-SEED-008 — Procest-Specific Seed Data

The `procest_register.json` MUST include seed data for default case types, status types, and role types to enable immediate case management after installation.

**Feature tier**: MVP

#### Scenario: Default case types seeded

- **GIVEN** a fresh Procest installation with the `procest_register.json` loaded
- **THEN** the following case types MUST be available:
  - "Omgevingsvergunning" (processingDeadline: P56D, published)
  - "Subsidieaanvraag" (processingDeadline: P42D, published)
  - "Klacht behandeling" (processingDeadline: P42D, published)
  - "Melding openbare ruimte" (processingDeadline: P14D, published)
- **AND** each case type MUST have linked status types in the correct order
- **AND** each case type MUST have at least one result type defined

#### Scenario: Default status types per case type

- **GIVEN** the seeded case type "Omgevingsvergunning"
- **THEN** it MUST have the following status types in order:
  1. "Ontvangen" (order: 1, isFinal: false)
  2. "In behandeling" (order: 2, isFinal: false)
  3. "Besluitvorming" (order: 3, isFinal: false)
  4. "Afgehandeld" (order: 4, isFinal: true)
- **AND** the "Klacht behandeling" case type MUST have:
  1. "Ontvangen" (order: 1)
  2. "Onderzoek" (order: 2)
  3. "Afgehandeld" (order: 3, isFinal: true)

#### Scenario: Default role types seeded

- **GIVEN** the seeded case type "Omgevingsvergunning"
- **THEN** the following role types MUST be available:
  - "Behandelaar" (handler role)
  - "Aanvrager" (initiator role)
  - "Gemachtigde" (authorized representative)
  - "Technisch adviseur" (advisor)
- **AND** these role types MUST be linkable to cases of this type

#### Scenario: Default result types seeded

- **GIVEN** the seeded case type "Omgevingsvergunning"
- **THEN** the following result types MUST be available:
  - "Vergunning verleend" (archiveAction: retain, retentionPeriod: P20Y)
  - "Vergunning geweigerd" (archiveAction: destroy, retentionPeriod: P10Y)
  - "Ingetrokken" (archiveAction: destroy, retentionPeriod: P5Y)

#### Scenario: Seed data enables immediate demo

- **GIVEN** all seed data is loaded (procest register + base registers)
- **WHEN** a user creates a case of type "Omgevingsvergunning" with title "Verbouwing woonhuis"
- **THEN** the case MUST be creatable without additional configuration
- **AND** a BRP person MUST be linkable as initiator
- **AND** a BAG address MUST be linkable as case object
- **AND** the full case lifecycle (status changes, tasks, decisions) MUST be walkable

---

### Requirement: REQ-SEED-009 — Seed Data Consistency Validation

The seed data MUST be internally consistent and pass validation checks.

**Feature tier**: MVP

#### Scenario: No orphan references

- **GIVEN** all seed data across all registers
- **THEN** every `partnerBsn` in BRP MUST reference an existing BRP person
- **AND** every `kinderen[].bsn` MUST reference an existing BRP person
- **AND** every `eigenaarBsn` in KVK MUST reference an existing BRP person
- **AND** every `locatieBagId` in DSO MUST reference an existing BAG nummeraanduiding
- **AND** every `vergaderingId` in ORI agendapunten MUST reference an existing vergadering

#### Scenario: Date consistency

- **GIVEN** all seed data
- **THEN** no person MUST have `geboortedatum` in the future
- **AND** no person MUST have `overlijdensdatum` before `geboortedatum`
- **AND** children MUST be born after both parents
- **AND** `datumOprichting` for KVK businesses MUST be before today
- **AND** `datumUitschrijving` MUST be after `datumOprichting` when set

#### Scenario: Identifier uniqueness

- **GIVEN** all seed data within a register
- **THEN** every BSN MUST be unique within the BRP register
- **AND** every KVK nummer MUST be unique within the KVK register
- **AND** every BAG identificatie MUST be unique within the BAG register
- **AND** every DSO zaaknummer MUST be unique within the DSO register

---

## Dependencies

- **OpenRegister core**: Register, schema, and object management; JSON configuration loading via `ConfigurationService`
- **OpenRegister CLI**: `occ openregister:load-register` command for loading register JSON files
- **Procest register**: `procest_register.json` defines case types, status types, role types, and other configuration
- **Pipelinq register**: `pipelinq_register.json` client schema -- Pipelinq clients reference KVK/BRP identifiers
- **GGM (ggm-openregister)**: The GGM schemas in `99-kern.openregister.json` provide an alternative, more detailed data model. The schemas defined in this spec are simplified versions optimized for seed data and app testing, not full GGM compliance.

---

## Standards & References

- **Haal Centraal BRP Personen Bevragen API v2** -- BRP person schema structure. Source: RVIG (Rijksdienst voor Identiteitsgegevens). URL: https://developer.rvig.nl/brp-api/overview/
- **KVK Handelsregister API** -- Basisprofiel and Vestigingsprofiel endpoints. Source: Kamer van Koophandel. URL: https://developers.kvk.nl/
- **BAG API Individuele Bevragingen v2** -- Nummeraanduiding, OpenbareRuimte, Woonplaats, Verblijfsobject, Pand. Source: Kadaster. URL: https://lvbag.github.io/BAG-API/
- **STAM v6 / IMAM** -- Standaard Aanvragen en Meldingen / Informatiemodel Aanvragen en Meldingen for DSO vergunningaanvragen. Source: IPLO / Ministerie van BZK. URL: https://iplo.nl/digitaal-stelsel/aansluiten/standaarden/stam-imam/
- **Popolo Data Standard** -- International standard for political entities (Person, Organization, Event, Motion, VoteEvent). Source: Popolo Project. URL: https://www.popoloproject.com/specs/
- **Open Raadsinformatie (ORI)** -- Open State Foundation project for standardizing Dutch council information. URL: https://openraadsinformatie.nl/
- **SBI (Standaard Bedrijfsindeling)** -- Official Dutch Standard Industrial Classification for business activity codes. Source: KVK/CBS.
- **BSN 11-proef** -- Checksum algorithm for Dutch citizen service numbers. The weighted sum `(d1*9 + d2*8 + d3*7 + d4*6 + d5*5 + d6*4 + d7*3 + d8*2 - d9*1)` must be divisible by 11 and not equal to 0.
- **GGM (Gemeentelijk Gegevensmodel) v2.5.0** -- Municipal data model. Used for entity naming alignment. Source: VNG. Available at `ggm-openregister/` in this workspace.
- **ZGW APIs (VNG)** -- Zaakgericht Werken APIs for case management alignment. Procest case-betrokkene linking uses ZGW conventions.
- **RVIG test BSN range** -- BSNs starting with `9999` are reserved for testing purposes by RVIG.

---

## Current Implementation Status

**Implemented in OpenRegister (not Procest).** All five base register JSON files are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. The files are NOT in the Procest codebase -- they live in the OpenRegister app which is the canonical home for base registry data. Procest and Pipelinq consume these registers after loading.

##### Using Mock Register Data

All five base registers are available in `openregister/lib/Settings/`:

| Register | File | Records | Slug | Schemas |
|----------|------|---------|------|---------|
| BRP | `brp_register.json` | 35 persons | `brp` | `ingeschreven-persoon` |
| KVK | `kvk_register.json` | 16 businesses + 14 branches | `kvk` | `maatschappelijke-activiteit`, `vestiging` |
| BAG | `bag_register.json` | 32 addresses + 21 objects + 21 buildings | `bag` | `nummeraanduiding`, `verblijfsobject`, `pand` |
| DSO | `dso_register.json` | 53 records | `dso` | `activiteit`, `locatie`, `omgevingsdocument`, `vergunningaanvraag` |
| ORI | `ori_register.json` | 115 records | `ori` | `vergadering`, `agendapunt`, `raadsdocument`, `stemming`, `raadslid`, `fractie` |

**Loading all registers:**
```bash
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/kvk_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json
```

**Or via the API:**
```bash
curl -X POST "http://localhost:8080/index.php/apps/openregister/api/registers/import" \
  -u admin:admin -H "Content-Type: application/json" \
  -d @openregister/lib/Settings/brp_register.json
```

**Test data for Procest use cases:**
- **Case with initiator (BRP)**: BSN `999993653` (Suzanne Moulin) -- link as case initiator via betrokkene
- **Case with BAG-object**: Use BAG nummeraanduiding records -- link address to bouwvergunning case (REQ-CDV-05b)
- **VTH with DSO vergunningaanvraag**: Use DSO `vergunningaanvraag` records for omgevingsvergunning intake testing
- **Legesberekening**: BAG `verblijfsobject` records include `oppervlakte` field for fee calculation
- **StUF-BG person lookup**: BSN `999993653` to test `npsLv01` query
- **ORI council data**: Use ORI records to test B&W besluit workflow with raadsinformatie

**Querying mock data:**
```bash
# Find person by BSN
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999993653" -u admin:admin

# Find BAG address
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{bag_register_id}/{nummeraanduiding_schema_id}?_search=1015" -u admin:admin
```

**Foundation available:**
- `SettingsService::loadConfiguration()` can load register JSON files from `lib/Settings/` (currently loads `procest_register.json`).
- The `InitializeSettings` repair step runs on app install/upgrade and calls `loadConfiguration()`.
- The GGM at `ggm-openregister/` provides full GGM schemas that could serve as a reference or alternative (955 schemas across 12 registers), but they contain no seed data.
- OpenCatalogi's `publication_register.json` demonstrates the `@self` seed object pattern in `components.objects`.
