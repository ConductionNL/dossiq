---
status: proposed
---

# Spec: portal-contribution (guarded citizen and inspector writes)

## ADDED Requirements

### Requirement: A portal write that names a case SHALL declare that case as a guarded reference (REQ-PC-20)

Every create the citizen audience declares that accepts a case reference
SHALL declare that field in `crossRefs`, naming the `case` schema and the
`portalSubject` scope field, and SHALL mark it required. No such create SHALL
accept a case reference without one.

#### Scenario: A citizen objects to their own case

- **GIVEN** the citizen audience's `createBezwaar`
- **WHEN** it is declared
- **THEN** `againstCaseId` SHALL be guarded against the citizen's own cases
- @e2e exclude {the guard is enforced inside Portaliq and has no dossiq browser path; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

#### Scenario: A citizen replies about their own case

- **GIVEN** the citizen audience's `replyToMessage`
- **WHEN** it is declared
- **THEN** `caseId` SHALL be guarded against the citizen's own cases, and the reply SHALL be scoped by who sent it
- @e2e exclude {same enforcement point; asserted in the same test}

### Requirement: What a portal write IS SHALL come from the server (REQ-PC-21)

The `kind` of a bezwaar and the `direction` of a message SHALL be stamped
from the action's `defaults` and SHALL NOT appear in its whitelisted fields.

#### Scenario: A bezwaar cannot arrive as a klacht

- **GIVEN** the citizen audience's `createBezwaar`
- **WHEN** it is declared
- **THEN** `kind` SHALL be absent from its fields and present in its defaults
- @e2e exclude {a manifest shape; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testTheKindAndDirectionAreStampedNotOffered}

### Requirement: The inspector submit SHALL accept no case or template (REQ-PC-22)

Submitting a checklist run SHALL be an update on a run the inspector is
already assigned, whose whitelisted fields carry neither the case nor the
template, and whose resulting status SHALL come from the action rather than
the request.

#### Scenario: An inspector submits the run they hold

- **GIVEN** the inspector audience
- **WHEN** its actions are declared
- **THEN** `submitChecklistRun` SHALL be an update scoped by `assignedInspectorRef`, accepting no `case` and no `template`
- @e2e exclude {an external inspector session cannot be staged from dossiq's own browser surface; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testInspectorContributionShape}
