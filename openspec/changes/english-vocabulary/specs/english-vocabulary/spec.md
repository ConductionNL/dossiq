## ADDED Requirements

### Requirement: A rename that would merge two schemas SHALL be blocked

A rename SHALL be rejected where the target name is already declared by an existing
schema in the same app, because register fragments merge by concatenating list values and
the result is a schema no payload satisfies.

#### Scenario: The target name already exists

- **WHEN** renaming `Besluit` to `Decision` while a `decision` schema already exists
- **THEN** the rename SHALL be blocked
- **AND** it SHALL be recognised as a schema merge rather than a rename

#### Scenario: The modelling question is escalated rather than resolved by renaming

- **WHEN** two schemas in different languages appear to model one concept
- **THEN** whether to unify them SHALL be decided as a modelling question
- **AND** the vocabulary change SHALL NOT make that decision implicitly

#### Scenario: The target name is checked against other apps too

- **WHEN** an English schema name is proposed
- **THEN** it SHALL be checked against every app's declared slugs
- **AND** a name already used by another app for a different concept SHALL be rejected

### Requirement: ZGW protocol vocabulary SHALL be preserved at the boundary

Resource names, joins and operations belonging to the ZGW API family SHALL keep the
standard's vocabulary. Only identifiers expressing procest's own logic SHALL be renamed.

#### Scenario: A protocol operation keeps its name

- **WHEN** a method performs a ZGW API operation against an endpoint of that name
- **THEN** the identifier SHALL be preserved
- **AND** it SHALL NOT be renamed for consistency with surrounding code

#### Scenario: App logic around a protocol operation is renamed

- **WHEN** a method expresses procest's own behaviour rather than a protocol call
- **THEN** it SHALL be renamed to English

#### Scenario: Classification is per identifier

- **WHEN** the code layer is renamed
- **THEN** each class and method SHALL be classified individually
- **AND** the rename SHALL NOT be applied by a script or pattern substitution

### Requirement: The case family SHALL be renamed only in a four-app window

`Zaak`, `zaakId` and `zaaktype` SHALL be renamed together with openconnector, docudesk and
pipelinq. procest owns the name; the other three hold foreign keys into it.

#### Scenario: All four apps land together

- **WHEN** `Zaak` is renamed to `Case`
- **THEN** the three dependent apps SHALL rename their keys in the same window
- **AND** procest SHALL NOT land the rename alone

#### Scenario: Silent breakage is anticipated rather than tested for

- **WHEN** assessing the risk of a unilateral rename
- **THEN** it SHALL be understood that consumers read with a null-coalescing default
- **AND** a passing test suite in any of the four apps SHALL NOT be treated as evidence

#### Scenario: The parent/child case relation follows

- **WHEN** the case family is renamed
- **THEN** the ZGW parent and child case relation SHALL be renamed consistently with it

### Requirement: Statutory domain concepts SHALL take English names with statute markers

Concepts defined by Dutch social-care, subsidy, mandate and administrative law SHALL be
renamed to English and SHALL carry a marker naming the statute.

#### Scenario: A social-care case type is renamed and marked

- **WHEN** a schema models a case under a named Dutch social-care act
- **THEN** it SHALL take an English name
- **AND** it SHALL carry a marker naming that act

#### Scenario: An EU-derived concept is internationalised without a marker

- **WHEN** a schema models a concept derived from EU law that the Dutch term merely
  translates
- **THEN** it SHALL take the international English term
- **AND** it SHALL NOT be treated as an NL-only statutory concept

#### Scenario: A statutory deadline concept is named for what it is

- **WHEN** a schema or class models a monitored statutory deadline
- **THEN** it SHALL be named for a deadline
- **AND** the Dutch word SHALL NOT be adopted as a fleet-wide term, because other apps use
  it for notice periods and terms of office

### Requirement: Colliding schemas shared with another app SHALL NOT be renamed unilaterally

Where procest declares a schema slug another app also declares, the rename SHALL be
deferred to the fleet change rather than decided by procest.

#### Scenario: A shared council-information slug is left alone

- **WHEN** procest declares a schema slug that openregister also declares
- **THEN** procest SHALL NOT rename it in this change
- **AND** the collision SHALL be escalated

#### Scenario: A one-sided rename is recognised as worse than the collision

- **WHEN** renaming only procest's half of a shared slug is considered
- **THEN** it SHALL be rejected
- **AND** the reason SHALL be that two apps would then hold divergent vocabularies for one slug

### Requirement: Register fragments SHALL be updated wherever they wire by class name

A register fragment entry naming a class SHALL be updated together with that class's
rename, covering handler, guard, precondition and equivalent references.

#### Scenario: A wired class is renamed

- **WHEN** a class referenced by name from a register fragment is renamed
- **THEN** every fragment entry naming it SHALL be updated in the same commit
- **AND** the change SHALL account for the reference failing silently rather than raising

#### Scenario: A lowercase-compared literal is checked

- **WHEN** a rename changes the casing of a value compared after lowercasing
- **THEN** the comparison SHALL be re-checked
- **AND** static analysis SHALL be run, because such a comparison becomes permanently
  unsatisfiable without any test failing
