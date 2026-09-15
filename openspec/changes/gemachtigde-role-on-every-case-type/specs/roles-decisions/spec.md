## ADDED Requirements

### Requirement: Every case type offers a Gemachtigde role (REQ-ROLE-009)

A generic `roleType` Gemachtigde with `genericRole: gemachtigde` and no
`caseType` SHALL be seeded once and offered on the Add party form of every
case type after the type's own role types. When a type declares its own
`gemachtigde` role, the generic one SHALL NOT be listed twice.

#### Scenario: A representative on a permit case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case of a type that declares no Gemachtigde role
- **WHEN** you press Add party and open the role type list
- **THEN** Gemachtigde SHALL be offered

#### Scenario: Bezwaar keeps one Gemachtigde
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a bezwaar case whose type declares its own Gemachtigde role
- **WHEN** you open the role type list
- **THEN** Gemachtigde SHALL be listed once

### Requirement: The Parties tab shows who is represented (REQ-ROLE-010)

A Gemachtigde row SHALL show the representative as participant and the
represented party in the column Represented by, read from `delegateFrom`.

#### Scenario: Represented party is visible
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a Gemachtigde role added for the requester of a case
- **WHEN** you open the Parties tab
- **THEN** the row SHALL show the representative and the requester under Represented by

### Requirement: The case declares the kinds of party it takes (REQ-ROLE-011)

The case schema SHALL declare `partyKinds` naming at least `person`,
`organisation` and `address`, each with a label. A write naming a kind the
schema does not declare SHALL be refused. The `address` kind SHALL hold the
role `locatie` and no other. `person` and `organisation` SHALL name no
roles, because a kind naming roles holds only those and a person link
carries a role type uuid as its role.

The schemas whose objects are parties SHALL declare
`x-openregister-party`, naming the kind and the properties carrying the
name, the addresses, the indicators and, for an organisation, the parent.

#### Scenario: A party of an undeclared kind is refused
@e2e exclude {the refusal is OpenRegister's validator; asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php, which checks the declaration this app writes}

- **GIVEN** a case whose schema declares person, organisation and address
- **WHEN** a party of another kind is added to it
- **THEN** the write SHALL be refused and the message SHALL name the kind

#### Scenario: An address holds only the location role
@e2e exclude {vocabulary shape, asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php::testTheCaseDeclaresTheKindsOfPartyItTakes}

- **GIVEN** the declaration above
- **WHEN** an address party is added in the role of representative
- **THEN** the write SHALL be refused, naming the roles that kind holds

### Requirement: Every case type offers the generic party roles (REQ-ROLE-012)

The case schema's link vocabulary SHALL carry the instance's own role
types first, then the generic party roles every case type offers:
requester, authorised representative, interested party, sender, addressee
and location. A generic role whose key a role type already claims SHALL
NOT be listed twice. The labels SHALL be translated, so a reader sees them
in their own language.

#### Scenario: A representative on a permit case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case of a type that declares no representative role type
- **WHEN** the vocabulary is read from the case schema
- **THEN** it SHALL carry `gemachtigde` after the type's own role types

#### Scenario: A role type keeps the key it claims
@e2e exclude {vocabulary shape, asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php::testAGenericRoleIsNotListedTwice}

- **GIVEN** an instance whose own role type is keyed `gemachtigde`
- **WHEN** the vocabulary is written
- **THEN** `gemachtigde` SHALL appear once, labelled by the role type

### Requirement: An indicator on a party is surfaced where the act is offered (REQ-ROLE-013)

An indicator a party carries SHALL be read where dossiq offers the act it
refuses, not only where the party is drawn. A file request to a party
whose indicator refuses a send SHALL be refused, naming the indicator and
the party, and that party SHALL be listed and unselectable with the reason
beside them. Publishing a decision on a case a party refuses publication
on SHALL be refused, naming the indicator and the party.

An instance whose OpenRegister carries no party model SHALL behave as it
did before: nothing refuses the act here, and the refusal that matters is
the one OpenRegister makes at the same two acts.

#### Scenario: A protected party is listed and cannot be asked for a file
@e2e exclude {the indicator is an OpenRegister party record; asserted in tests/Unit/Controller/FileRequestControllerTest.php::testAPartyWhoseIndicatorRefusesASendIsListedAndNamed}

- **GIVEN** a party on a case whose indicator refuses a send
- **WHEN** the handler opens the file request dialog
- **THEN** that party SHALL be listed, not selectable, and the indicator SHALL be named

#### Scenario: The send itself is refused, not only the dialog
@e2e exclude {server-side guard, asserted in tests/Unit/Service/People/FileRequestServiceTest.php::testAPartyWhoseIndicatorRefusesASendIsNotSentTo}

- **GIVEN** the party above
- **WHEN** a file request is posted for them anyway
- **THEN** the response SHALL be 403 naming the indicator and the party, and no share SHALL be created

#### Scenario: Publishing is refused and says why
@e2e exclude {the publication path needs a DROP/LVBB endpoint no e2e instance has; asserted in tests/vitest/caseParties.spec.js}

- **GIVEN** a case with a party whose indicator refuses publication
- **WHEN** the handler opens the publication panel
- **THEN** the publish button SHALL be disabled and the indicator and party SHALL be named

### Requirement: The case page shows who is on the case, in their roles (REQ-ROLE-014)

The People tab SHALL carry a Roles section reading the parties of the
case grouped by role. The role holding the primary party SHALL be
rendered first, and the primary party first within it. A role SHALL be
labelled by the vocabulary rather than by its key. A read that could not
be made SHALL say so, and SHALL NOT be drawn as a case with no parties.

#### Scenario: The primary party is first
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with a primary party and two other parties in other roles
- **WHEN** the handler opens the People tab
- **THEN** the primary party SHALL be the first party shown, marked as primary

#### Scenario: A failed read is not an empty case
@e2e exclude {the failure is an OpenRegister outage; asserted in tests/vitest/caseParties.spec.js}

- **WHEN** the parties of a case cannot be read
- **THEN** the section SHALL say the parties could not be read, and SHALL NOT show an empty party list

### Requirement: A picker resolves an address before creating a second party (REQ-ROLE-015)

Before a requester is recorded from a source that carries no register
row, the picker SHALL ask which party already holds that address. When
one does, the requester SHALL name that party rather than a second
record. When none does, the choice SHALL be recorded as it was.

#### Scenario: A contact whose address a party already holds
@e2e exclude {the resolve endpoint is OpenRegister's; asserted in tests/vitest/initiatorPicker.spec.js}

- **GIVEN** a party holding the address `jan@example.nl`
- **WHEN** a handler picks a Nextcloud contact with that address as the requester
- **THEN** the case SHALL name that party as its requester and no second party SHALL be created

#### Scenario: An address nobody holds
@e2e exclude {same endpoint; asserted in tests/vitest/initiatorPicker.spec.js}

- **GIVEN** an address no party holds
- **WHEN** the same choice is made
- **THEN** the requester SHALL be recorded exactly as it was before this requirement
