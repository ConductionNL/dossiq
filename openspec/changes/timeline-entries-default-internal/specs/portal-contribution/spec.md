## ADDED Requirements

### Requirement: Feed entries default to internal and the portal sees only public ones (REQ-PC-10)

Every note, contact moment and status change dossiq writes SHALL carry the
visibility flag, defaulting to internal; the note and contact forms SHALL
offer "Visible to the applicant". Berichtenbox messages, portal messages
and delivered beschikkingen SHALL be public. The portal contribution,
`#PublicStatus` and an access link the case is shared through SHALL show only
public entries, and no entry any of them serves SHALL name who wrote it.

#### Scenario: A note stays inside
@e2e tests/e2e/timeline-visibility.spec.ts

- **GIVEN** you add a note to a case without ticking Visible to the applicant
- **WHEN** the applicant opens the case through the link it was shared with
- **THEN** the note SHALL NOT be shown

#### Scenario: A delivered letter is on the timeline
@e2e tests/e2e/timeline-visibility.spec.ts

- **GIVEN** a beschikking delivered on the case
- **WHEN** the applicant opens the case through the link it was shared with
- **THEN** the delivery SHALL be listed with its date

#### Scenario: The contribution carries the same list
@e2e exclude cross-app; covered by PortalContributionProviderTest asserting the timeline equals `publicEntries()`

- **GIVEN** a case with two public and three internal entries
- **WHEN** the contribution is built
- **THEN** its timeline SHALL hold the two public entries
