## MODIFIED Requirements

### Requirement: Case creation offers an initiator type selector

You pick the citizen, company or contact when you file a case, and again when
you edit it. The New case form on `Dashboard` (header action `new-case`) and
the edit form that widget `case-core` opens on `CaseDetail` SHALL render the
`requester` field with `InitiatorPicker` through `fieldOverrides`. The picker
SHALL offer the types Person, Company and Contact, mapping to the ZGW
betrokkene types (natuurlijk persoon, niet-natuurlijk persoon, intern contact).
Selecting a requester SHALL stay optional; a case without one saves as before.

#### Scenario: Agent picks an initiator type
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a handler on `Dashboard`
- **WHEN** they open New case
- **THEN** the form SHALL show a Requester field rendered by the initiator picker, enabled
- **AND** the picker SHALL offer the types Person, Company and Contact
- **AND** Create SHALL stay enabled with no requester chosen

#### Scenario: The edit form carries the picker
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a seeded case without a requester
- **WHEN** the handler opens Edit on the case page
- **THEN** the Requester field SHALL be enabled and rendered by the initiator picker
- **AND** no tooltip SHALL say the field is set by an integration

### Requirement: Selected initiator is stored on the case as a projection of the canonical requester

You save the case and the requester is on it once, with a display projection.
Confirming a selection SHALL write `case.requester` (the uuid of the chosen
`brpPerson` or `kvkCompany` row) and the projection fields `initiatorType`
(`person | company | contact`), `initiatorSourceId` (BSN, KvK number or
contact URI) and `initiatorDisplayName` in the same save. A contact has no
register row, so a contact selection SHALL fill the projection and leave
`requester` empty. There SHALL be one write path: the picker never writes a
second requester field, and a case that arrives through the `ns#Case`
semantic handoff keeps writing `requester` as today.

#### Scenario: Selection persists on the case
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** the seeded BRP register set
- **WHEN** a handler files a case and picks a seeded persona as Person
- **THEN** the saved case SHALL carry `requester` equal to that `brpPerson` row's uuid
- **AND** `initiatorType` SHALL be `person`, `initiatorSourceId` the persona's BSN, `initiatorDisplayName` the persona's name

#### Scenario: A company selection persists as uuid and projection
@e2e tests/e2e/case-requester.spec.ts

- **WHEN** a handler edits a case and picks KvK 69599084 as Company
- **THEN** the saved case SHALL carry `requester` equal to that `kvkCompany` row's uuid
- **AND** `initiatorType` SHALL be `company` and `initiatorSourceId` `69599084`

#### Scenario: Schema extension is additive

@e2e exclude Schema-level additive guarantee (no browser surface): PHPUnit (BrpKvkRegisterSetsTest::testCaseSchemaInitiatorFieldsAreAdditive) proves the three fields are optional and absent from case.required, so pre-existing cases validate unchanged.

- **GIVEN** cases created before this change
- **WHEN** the extended `case` schema is imported
- **THEN** existing cases MUST remain valid with the initiator fields absent

## ADDED Requirements

### Requirement: The register sets provide the requester type (REQ-IS-4)

You get an enabled Requester field because the fleet can answer for it.
`brpPerson` and `kvkCompany` in `lib/Settings/register.d/25-brp-kvk.json`
SHALL declare `implements: ["https://openregister.app/ns#Requester"]`.
`case.requester` SHALL keep its `referenceSemanticType`; it SHALL NOT become a
`$ref` to one schema, so the semantic handoff keeps its write path.

#### Scenario: Two schemas implement the requester type

@e2e exclude Schema declaration with no browser surface: PHPUnit (BrpKvkRegisterSetsTest::testPartySchemasImplementRequester) reads the fragment and asserts both schemas list the URI; the enabled field it produces is asserted by the picker scenarios above.

- **WHEN** the register configuration is imported
- **THEN** `brpPerson` and `kvkCompany` MUST list `https://openregister.app/ns#Requester` under `implements`
- **AND** `case.requester` MUST still carry `referenceSemanticType` with that URI

#### Scenario: The form no longer disables the field
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** the imported register configuration
- **WHEN** a handler opens New case on `Dashboard`
- **THEN** the Requester field SHALL NOT carry the disabled state or the no-provider tooltip
