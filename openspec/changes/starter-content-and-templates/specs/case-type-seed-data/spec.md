## ADDED Requirements

### Requirement: A case type carries its own handling switches (REQ-SEED-01)

A case type SHALL carry, on itself, the group that handles it by default,
the handler it falls to, which automatic messages go out, and which
intake screen is used. Every reader of that behaviour SHALL read the case
type and SHALL NOT hold its own copy. Publishing a case type SHALL refuse
a declared switch that no reader reads.

#### Scenario: an administrator changes the handling group in one place
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a case type whose default group is Vergunningen
- **WHEN** an administrator changes it to Toezicht and saves
- **THEN** a new case of that type SHALL be routed to Toezicht
- **AND** no other configuration SHALL need changing

#### Scenario: a switch nothing reads cannot be published

- **GIVEN** a case type carrying a handling switch with no reader
- **WHEN** it is published
- **THEN** publication SHALL refuse
- **AND** the refusal SHALL name the switch

#### Scenario: the intake screen follows the case type
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** two case types naming different intake screens
- **WHEN** a handler starts a case of each
- **THEN** each SHALL open the screen its case type names

### Requirement: The shipped configuration is named and versioned (REQ-SEED-02)

Every object dossiq seeds SHALL record the name and the version of the set
it came from. An administrator SHALL be able to read, per object, whether
it is shipped and untouched, shipped and changed locally, or locally
authored. A new version of a shipped set SHALL be offered for adoption
per object and SHALL NOT overwrite a locally changed object without being
accepted.

#### Scenario: an administrator sees what shipped and what they changed
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a seeded case type an administrator edited
- **WHEN** they open the shipped configuration screen
- **THEN** it SHALL read shipped and changed locally
- **AND** it SHALL name the set and version it came from

#### Scenario: an upgrade does not overwrite a local change

- **GIVEN** a shipped case type changed locally
- **WHEN** a newer version of the shipped set is installed
- **THEN** the local object SHALL be unchanged
- **AND** the newer version SHALL be offered for adoption

#### Scenario: an untouched shipped object takes the new version
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a shipped case type nobody edited
- **WHEN** the administrator adopts the newer set
- **THEN** the object SHALL carry the new version

### Requirement: A named municipal role set ships and is adopted (REQ-SEED-03)

dossiq SHALL ship a named set of municipal role types covering at least
behandelaar, coordinator, teamleider, intake, archief and lezer. The set
SHALL be dormant until an administrator adopts it. Adoption SHALL be one
act, SHALL be recorded with who and when, and SHALL be reversible while
no grant has been made against a role in it.

#### Scenario: the roles are there to adopt, not already granted
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a fresh instance
- **WHEN** an administrator opens the roles screen
- **THEN** the shipped set SHALL be offered
- **AND** no role in it SHALL yet hold a grant

#### Scenario: adoption is undone while nothing uses it

- **GIVEN** an adopted role set with no grants against it
- **WHEN** the administrator undoes the adoption
- **THEN** the roles SHALL be dormant again

#### Scenario: adoption is not undone once a grant exists

- **GIVEN** an adopted role set with one grant against a role
- **WHEN** the administrator tries to undo the adoption
- **THEN** it SHALL refuse
- **AND** the refusal SHALL name the role that is in use

### Requirement: A case type is retired and restored, never deleted (REQ-SEED-04)

A case type SHALL carry a state of draft, in use or retired, derived from
its `isDraft`, `validFrom` and `validUntil` and named as one value. A
retired case type SHALL accept no new case, SHALL keep every existing case
readable, and SHALL let a running case of that type be finished. Retiring
and restoring SHALL each be recorded with who did it and when.

#### Scenario: a regeling that ended stops taking new cases
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a case type with running cases
- **WHEN** an administrator retires it
- **THEN** it SHALL NOT be offered when a handler starts a case
- **AND** its running cases SHALL still be openable

#### Scenario: a running case of a retired type is finished
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a running case of a retired case type
- **WHEN** the handler completes its last phase
- **THEN** the case SHALL close normally

#### Scenario: a retired case type comes back

- **GIVEN** a retired case type
- **WHEN** an administrator restores it
- **THEN** it SHALL be offered again
- **AND** the restoration SHALL name who did it

### Requirement: A new case type or domain starts from an existing one (REQ-SEED-05)

An administrator SHALL create a case type by copying an existing case type
or one marked as a template. An administrator SHALL create a domain by
copying another domain, carrying its case types, role types, templates and
code lists. What a copy carries SHALL be a declared list. A copy that
could not carry something SHALL say so on its result and SHALL NOT report
success.

#### Scenario: a new case type starts from a known good one
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a published case type
- **WHEN** an administrator copies it and names the copy
- **THEN** the copy SHALL carry its phases, terms, roles and templates
- **AND** the copy SHALL be a draft

#### Scenario: a whole domain is stood up from another
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a configured domain
- **WHEN** an administrator copies it
- **THEN** the new domain SHALL carry its case types, role types, templates and code lists

#### Scenario: a copy that dropped something says so

- **GIVEN** a case type referencing a template the copy cannot reach
- **WHEN** it is copied
- **THEN** the result SHALL name what was not carried
- **AND** it SHALL NOT read as a complete copy
