## ADDED Requirements

### Requirement: A case type narrows who may be assigned at creation (REQ-TRIAGE-03)

A case type SHALL declare which groups and which people may be chosen as
the handler when a case of that type is created. The picker SHALL read
that declaration. A write naming a group or a person outside it SHALL be
refused with a 4xx carrying `{message, error}`, naming the declaration
that refused it. The narrowing SHALL be enforced on the write and not only
in the picker.

#### Scenario: the picker offers only the declared groups
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case type declaring two allowed groups
- **WHEN** a handler creates a case of that type
- **THEN** the group picker SHALL offer those two only

#### Scenario: the API refuses a group the picker never offered
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** the same case type
- **WHEN** a case is written naming a third group
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the case type declaration

#### Scenario: a case type with no narrowing keeps every choice

- **GIVEN** a case type declaring no narrowing
- **WHEN** a handler creates a case
- **THEN** every group SHALL be offered

### Requirement: A refused intake goes to a named department and role (REQ-TRIAGE-04)

Refusal SHALL be an outcome of routing beside its destinations. A case
type or a routing rule SHALL declare the department and the role a refused
case goes to. Refusing SHALL record the reason and who refused it. A
refused case SHALL stay in the register, SHALL stay findable in search,
and SHALL NOT be deleted or hidden.

#### Scenario: a refused case lands somewhere, per Awb 2:3
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case type declaring refusals go to Juridische Zaken, role intake
- **WHEN** an intake worker refuses a case with a reason
- **THEN** the case SHALL be assigned to that department and role
- **AND** the reason and the refuser SHALL be recorded

#### Scenario: a refused case is not a lost case
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a refused case
- **WHEN** a handler searches for its number
- **THEN** it SHALL be found

#### Scenario: refusal with no declared destination is refused

- **GIVEN** a case type declaring no refusal destination
- **WHEN** an intake worker refuses a case
- **THEN** the refusal SHALL be refused
- **AND** it SHALL say the destination is not configured

### Requirement: A triage item sleeps until a date and comes back (REQ-TRIAGE-05)

A triage item SHALL be sleepable until a date, with a reason. A sleeping
item SHALL leave the triage queue and SHALL return to the queue it came
from on its date. Sleeping SHALL apply to items not yet accepted as cases
and SHALL NOT suspend any statutory term.

#### Scenario: nothing happens until 1 March
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a triage item
- **WHEN** an intake worker sleeps it until 1 March with a reason
- **THEN** it SHALL leave the triage queue
- **AND** the reason and the date SHALL be recorded

#### Scenario: it returns to the queue, not to a person
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** an item slept until yesterday by someone who has since left
- **WHEN** the triage queue is read
- **THEN** the item SHALL be in it
- **AND** it SHALL be unassigned

#### Scenario: sleeping never stops a statutory clock

- **GIVEN** an accepted case with a running statutory term
- **WHEN** a sleep is attempted on it
- **THEN** it SHALL be refused
- **AND** it SHALL name the suspension act instead

### Requirement: One submission opens several cases, tracked together (REQ-TRIAGE-06)

An intake form SHALL be able to declare several destinations, each naming
a case type and a department. Submitting SHALL create one case per
destination. Every created case SHALL carry a relation to the submission
and to the other cases created from it. A department SHALL see its own
case and SHALL NOT see the content of the others. Until openregister's
named relation type exists, the relation SHALL use the existing
related-cases link and the limitation SHALL be recorded on the form.

#### Scenario: one melding opens a handhaving case and an onderhoud case
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** an intake form declaring two destinations in two departments
- **WHEN** a citizen submits it
- **THEN** two cases SHALL be created
- **AND** each SHALL be in its declared department

#### Scenario: the cases know about each other
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** two cases created from one submission
- **WHEN** either is opened
- **THEN** it SHALL name the submission and the sibling case

#### Scenario: a department does not read the other's case

- **GIVEN** two cases created from one submission in two departments
- **WHEN** a handler of the first department opens the sibling
- **THEN** its content SHALL NOT be readable

#### Scenario: a destination that cannot be created stops nothing silently

- **GIVEN** a form declaring a destination whose case type is retired
- **WHEN** the form is submitted
- **THEN** the other cases SHALL be created
- **AND** the failed destination SHALL be reported with its reason

### Requirement: The fan-out ties its cases with a relation that is symmetric by declaration (REQ-TRIAGE-07)

Cases opened by one submission SHALL be tied with a relation type the case
schema declares symmetric, so both ends read the same name because that is what
the relation is. The answer SHALL NOT report a missing inverse.

#### Scenario: both siblings read the link the same way

- **GIVEN** a submission that opens two cases
- **WHEN** either case's relations are read
- **THEN** the other SHALL appear under the same name from both sides
- @e2e exclude {the fan-out has no form to submit until buildiq ships one; asserted in tests/Unit/Service/IntakeFanOutTest.php::testTheSiblingsAreTiedWithTheSymmetricRelation}
