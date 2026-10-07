# Spec: portal-contribution (case actions on the case page)

## ADDED Requirements

### Requirement: The case page offers bezwaar and klacht for the case on screen

The resident's case page SHALL declare "Bezwaar maken" and "Klacht indienen"
as calls to action with `withRecord: true`, and each action SHALL name
`againstCaseId` as its `recordField`, guarded against the citizen's own cases.
The overview SHALL NOT declare them. A klacht's case reference SHALL NOT be
required.

#### Scenario: A resident objects from their case page

- **GIVEN** a resident on the page of one of their cases
- **WHEN** they press "Bezwaar maken"
- **THEN** the bezwaar SHALL carry that case in `againstCaseId`
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalCasePageTest.php::testTheCasePageCarriesTheResidentsOwnCase and PortalContributionProviderTest::testTheReplyActionNamesTheFieldARecordLandsIn}

#### Scenario: A klacht without a case is still accepted

- **GIVEN** the "Klacht indienen" page, which opens no case
- **WHEN** the resident files a klacht
- **THEN** the klacht SHALL be accepted without `againstCaseId`
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

### Requirement: The case page orders its blocks as the Zaak board

The case page SHALL declare Gegevens before Stukken and the timeline.

#### Scenario: The facts come before the documents

- **GIVEN** the resident's case page
- **WHEN** it is declared
- **THEN** its blocks SHALL read tasks, steps, detail, documents, timeline, the calls to action, citizenCase
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalCasePageTest.php::testTheCasePageCarriesTheResidentsOwnCase}

## MODIFIED Requirements

### Requirement: A portal write that names a case SHALL declare that case as a guarded reference (REQ-PC-20)

Every create the citizen audience declares that accepts a case reference
SHALL declare that field in `crossRefs`, naming the `case` schema and the
`portalSubject` scope field. It SHALL mark it required, except for
`createKlacht`, whose case is optional: a reference it carries is still
guarded against the citizen's own cases. No such create SHALL accept a case
reference without a guard.

#### Scenario: A citizen complains about their own case

- **GIVEN** the citizen audience's `createKlacht`
- **WHEN** it is declared
- **THEN** `againstCaseId` SHALL be guarded against the citizen's own cases and SHALL NOT be required
- @e2e exclude {the guard is enforced inside Portaliq; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}
