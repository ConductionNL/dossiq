## ADDED Requirements

### Requirement: A case type declares the roles a case can be linked with (REQ-LCR-1)
A case type MUST be able to declare relation roles in `relations[]`. Each role
names what it points at (`case`, or `object` with the config key of the target
schema), the relation type it writes (one of the types `CaseRelationService`
knows), whether intake needs it, and optionally the record it opens (schema
config key, the field that holds the case, the field that holds the target, and
an optional actor field). A role that is not well formed MUST be refused with a
sentence that names the role and the field.

#### Scenario: A well-formed role is read
@e2e exclude Read by RoleLinkDefinitionTest against the real caseType schema; the browser journey belongs to the package that declares the first role (bezwaar, R1).
- **GIVEN** a case type with a role `reviews` pointing at an `object` in `decision_schema`
- **WHEN** the role is read
- **THEN** it MUST answer the target, the relation type and the record it opens

#### Scenario: A role without a target schema is refused
@e2e exclude Validation only, covered by RoleLinkDefinitionTest.
- **GIVEN** a role pointing at an `object` with no `targetSchema`
- **WHEN** the role is read
- **THEN** it MUST be refused naming `targetSchema`

### Requirement: Linking a case with a role writes the relation and the record (REQ-LCR-2)
`POST /api/cases/{caseId}/links/{role}` MUST link the case to the target the
caller names, as the role declares: when the role points at an object, the case
the object belongs to is the case linked. The relation MUST be written through
`CaseRelationService`, and the record the role declares MUST be written with the
case and target the server resolved and the actor from the session, never the
values a caller supplies for those fields. A role the case type does not declare
MUST be refused. Linking the same pair twice MUST NOT write a second relation.

#### Scenario: A case is linked to the case of the object it names
@e2e exclude RoleLinkServiceTest drives the real service over an in-memory register; the Newman collection of the first package asserts it on an instance.
- **GIVEN** a fixture case type with role `reviews` on `decision_schema`, record schema `review_schema`
- **AND** decision D belonging to case P
- **WHEN** case C is linked with role `reviews` to D, the payload naming another case and another actor
- **THEN** C MUST be related to P with the role's relation type
- **AND** a `review_schema` record MUST hold case C, target D and the session user

#### Scenario: An undeclared role is refused
@e2e exclude Covered by RoleLinkServiceTest and CaseLinkControllerTest.
- **WHEN** case C is linked with a role its case type does not declare
- **THEN** the request MUST be refused with HTTP 400 naming the role

### Requirement: The case says which required roles are missing (REQ-LCR-3)
`GET /api/cases/{caseId}/links` MUST list the roles the case type declares and,
for every role with `requiredAtIntake`, whether a record or relation for it
exists yet.

#### Scenario: A required role with no link is reported missing
@e2e exclude Covered by RoleLinkServiceTest; the intake screen that reads it ships with the first package.
- **GIVEN** a role `reviews` with `requiredAtIntake: true` and no record for case C
- **WHEN** the roles of C are listed
- **THEN** `reviews` MUST be reported missing

### Requirement: A marker rule derives a flag on the referenced object (REQ-LCR-4)
When an object of a rule's source schema is created or updated and references a
target through the rule's field, the rule's marker MUST be set on the target to
whether every condition of the rule holds, and MUST NOT be written when it
already has that value. A failing rule MUST NOT block the write that triggered
it.

#### Scenario: The marker follows the source
@e2e exclude LinkMarkerRuleTest and LinkMarkerListenerTest; the package Newman collection asserts the configured marker on an instance.
- **GIVEN** a rule on source `appeal_schema` field `sourceRecord`, marker `followUpOpen`, conditions target status in `[closed]` and source `filedOn` present
- **WHEN** an appeal referencing a closed target with `filedOn` set is saved
- **THEN** the target's `followUpOpen` MUST be true

### Requirement: An outcome cascade runs its configured steps (REQ-LCR-5)
A named cascade MUST run its steps in order on the target object: set fields on
the target, set fields on every object that references it through a named field,
and run a named transition on the target's case. A value MAY come from the
caller's input by name; a missing input MUST refuse the cascade before anything
is written. The caller MUST hold mutation access to the target's case.

#### Scenario: A cascade freezes the target and its dependents
@e2e exclude OutcomeCascadeTest over the in-memory register.
- **GIVEN** cascade `hold` that sets target `status` to `held` and dependents' `status` to `paused`
- **WHEN** it runs on target T with two dependents
- **THEN** T and both dependents MUST carry the new status
