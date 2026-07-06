# initiator-selection Specification

**Status:** done
**OpenSpec changes**: [brp-kvk-register-sets](../../changes/archive/2026-07-06-brp-kvk-register-sets/) _(archived 2026-07-06)_
**Scope:** Initiator (indiener) selection during case creation — cross-source search over the BRP/KvK register sets and Nextcloud Contacts
**Depends on:** `brp-register` / `kvk-register` (this change — the searched register tier); `semantic-case-intake` (requester semantic reference is canonical — one write path); `external-integrations-test-environments` (register → live-adapter search fallback per its config tiers — owned there, not here)
**Standards:** GEMMA Zaakafhandel (Rol "initiator" is a required betrokkene on every zaak), ZGW ZRC Rol betrokkene (natuurlijk persoon / niet-natuurlijk persoon), CMMN CaseFileItem (initiator as case file data)
**Feature tier:** MVP

## Requirements

### Requirement: Case creation offers an initiator type selector

The case create/edit flow SHALL offer an initiator picker (registered component in
`src/registry.js`) — the flow is manifest-driven at HEAD: the Cases index add form and
`StartCaseWidget` (there is no `CaseCreateDialog.vue`) — with type selection
**Person / Company / Contact**, mapping to the
ZGW betrokkene types (natuurlijk persoon / niet-natuurlijk persoon / intern contact). Selecting an
initiator SHALL be optional at creation time (existing flows keep working unchanged).

#### Scenario: Agent picks an initiator type

- **GIVEN** a user creating a case
- **WHEN** they open the initiator picker
- **THEN** the picker MUST offer the types Person, Company, and Contact
- **AND** the case MUST remain creatable without selecting any initiator

### Requirement: Cross-source initiator search returns unified results

The initiator picker SHALL search, per selected type: `brpPerson` register objects (Person) and
`kvkCompany` register objects (Company) via the OpenRegister objects API (frontend store — thin
client, no procest backend CRUD wrapper), and Nextcloud Contacts (Contact) via the Contacts API.
Results SHALL be presented uniformly (display name + identifying number/detail). When the Contacts
app is unavailable, the Contact source SHALL degrade to an explicit empty state, never an error.
The register → live BRP/KvK fallback is owned by `external-integrations-test-environments` and is
out of scope here: this picker queries the register tier.

#### Scenario: Person search hits the BRP register set

- **GIVEN** the seeded BRP register set
- **WHEN** the user searches a seeded persona's name with type Person
- **THEN** the matching `brpPerson` rows MUST be listed with name, birthdate, and BSN

#### Scenario: Company search hits the KvK register set

- **WHEN** the user searches "69599084" or a seeded handelsnaam with type Company
- **THEN** the matching `kvkCompany` row MUST be listed with handelsnaam and kvkNummer

#### Scenario: Contacts source degrades gracefully

- **GIVEN** the Nextcloud Contacts app is not installed
- **WHEN** the user searches with type Contact
- **THEN** the picker MUST show an explicit empty/unavailable state and no error toast

### Requirement: Selected initiator is stored on the case as a projection of the canonical requester

Confirming a selection SHALL store the initiator on the case via the additive `case` schema fields
`initiatorType` (`person | company | contact`), `initiatorSourceId` (BSN / KvK number / contact
URI), and `initiatorDisplayName`. These fields are the **display projection** of the canonical
ADR-048 requester semantic reference owned by `semantic-case-intake`: where that change has landed,
the picker SHALL write the semantic reference and fill the projection from it — one write path,
never a second requester field.

#### Scenario: Selection persists on the case

- **WHEN** a user selects seeded persona X and saves the case
- **THEN** the case object MUST carry `initiatorType: person`, the persona's BSN as
  `initiatorSourceId`, and the persona's display name as `initiatorDisplayName`

#### Scenario: Schema extension is additive

@e2e exclude Schema-level additive guarantee (no browser surface): PHPUnit (BrpKvkRegisterSetsTest::testCaseSchemaInitiatorFieldsAreAdditive) proves the three fields are optional and absent from case.required, so pre-existing cases validate unchanged.

- **GIVEN** cases created before this change
- **WHEN** the extended `case` schema is imported
- **THEN** existing cases MUST remain valid with the initiator fields absent
