## ADDED Requirements

### Requirement: A case carries a per-user read state (REQ-URS-01)

dossiq SHALL render openregister's per-user read state on `#Cases` and
`#Queue`, and SHALL offer mark as read and mark as unread on a case and on
a single message. dossiq SHALL NOT store a read state of its own.

#### Scenario: what moved overnight is visible from the list
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a queue of cases, three of which changed since the handler last looked
- **WHEN** they open the queue
- **THEN** those three SHALL read unread

#### Scenario: a handler puts a case back to unread
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case the handler has opened
- **WHEN** they mark it unread
- **THEN** it SHALL read unread in the list again

#### Scenario: dossiq stores no read state

- **GIVEN** the dossiq tree
- **WHEN** it is read for a per-user read marker
- **THEN** none SHALL exist

### Requirement: The case page says which of its panels holds the unread thing (REQ-URS-02)

A case SHALL show which of its panels hold something unread, so a handler
sees where to look rather than only that something changed, and reading a
panel SHALL clear that panel's count without clearing the others.

The count is rendered beside the panels rather than on the tab itself.
`CnTabsWidget` draws a label and an icon per tab, takes no badge and does
not emit its tab change, so an app cannot decorate a tab and cannot learn
that one was opened. That affordance belongs in `nextcloud-vue`, where
every list and detail page in the fleet gets it; until it lands, the
gesture that clears a panel is the strip's own, and it makes the same
write opening the tab will make.

#### Scenario: a document waiting on a case is visible from its header
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case with a newly added document
- **WHEN** a handler opens the case
- **THEN** the case page SHALL name its files as holding something unread

#### Scenario: opening the case does not clear where the unread thing is
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case whose files hold something unread
- **WHEN** the handler opens the case
- **THEN** the case SHALL read read
- **AND** its files SHALL still read unread

#### Scenario: reading a panel clears its own count and nothing else
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case whose files read unread
- **WHEN** the handler reads them
- **THEN** the files SHALL no longer read unread

### Requirement: A notification clears when its subject is opened (REQ-URS-03)

When a handler opens the thing a notification was about, that notification
SHALL clear without a separate dismissal.

#### Scenario: doing the work empties the bell
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a notification about a case
- **WHEN** the handler opens that case
- **THEN** the notification SHALL clear
- **AND** no separate dismissal SHALL be asked for

#### Scenario: reading one panel clears only the notices about that panel
@e2e exclude a notice is cleared by the same write that records the read, inside OpenRegister's NotificationClearingService; a browser can drive the write but cannot seed a notice for another user to see cleared. openregister's NotificationClearingServiceTest names both sides.

- **GIVEN** notices about two panels of one case
- **WHEN** the handler reads one of them
- **THEN** only that panel's notices SHALL clear

### Requirement: What makes a case unread is declared per case type (REQ-URS-04)

A case type SHALL declare which changes make a case unread, defaulting to
a status change, a new document and a new message. A change the case type
does not name SHALL NOT make a case unread.

The declaration is enforced where OpenRegister reads it, which is the
`case` schema's `x-openregister-read-state` block: its evaluator resolves
the annotation per schema, never per object. So the block carries the
whole vocabulary a case type may name, and the case type's own list is
the authoring surface over it. Narrowing per case type needs a per-object
hook OpenRegister does not have, and that ask is recorded in this
change's tasks.

#### Scenario: a bulk correction does not light up four hundred rows
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a field that is not among the declared unread changes
- **WHEN** that field is changed in bulk
- **THEN** no case SHALL read unread because of it

#### Scenario: a status change is among the declared unread changes
@e2e exclude the write invalidates every read state EXCEPT the actor's, so a browser signed in as one user cannot see its own row dropped; proving it needs two users, which is openregister's ReadStateServiceTest.

- **GIVEN** a case type with the default declaration
- **WHEN** a case's status changes
- **THEN** it SHALL read unread for the handlers who have seen it before

#### Scenario: a case type names a change nobody recognises

- **GIVEN** a case type naming a trigger outside the vocabulary
- **WHEN** it is published
- **THEN** publication SHALL say so rather than drop the name in silence
