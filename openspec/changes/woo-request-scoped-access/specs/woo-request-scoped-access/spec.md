## ADDED Requirements

### Requirement: A Woo document assessment is private to the request (REQ-WSA-001)

The `wooDocumentAssessment` schema SHALL carry the authorization block
`{"scope": "private", "read": ["authenticated"], "create": ["authenticated"], "update": ["authenticated"], "delete": ["dossiq-coordinators"]}`,
and its schema version SHALL be raised so the block reaches an installed instance. An
authenticated user who is not the assessment's author, not an administrator, not a member of
`dossiq-coordinators` and holds no grant on it SHALL NOT find it in a list and SHALL NOT read it,
through dossiq's routes or the OpenRegister API.

#### Scenario: A user not assigned to the request reads nothing
- **GIVEN** Woo case X assigned to `j.devries` with three assessments, and user `p.bakker` who is not assigned to X and not a coordinator
- **WHEN** `p.bakker` lists `wooDocumentAssessment` objects through the OpenRegister API, and reads one of X's assessments by its uuid
- **THEN** the list SHALL hold none of X's assessments and the read SHALL be refused

#### Scenario: The block reaches the instance
- **GIVEN** an instance upgraded to this change
- **WHEN** the `wooDocumentAssessment` schema is read through the OpenRegister API
- **THEN** its `authorization` SHALL carry `scope` `private`

### Requirement: The Woo case type keeps its assessments private to the request (REQ-WSA-002)

Decision 182: REQ-WSA-002 to REQ-WSA-006 are the generic capability `case-reviewer-access`
(REQ-CRA-001 to REQ-CRA-004), configured here. The Woo case type (`register.d/81-woo-verzoek.json`)
SHALL declare `reviewerAccess` with `records: [{schemaKey: woo_assessment_schema, caseField:
caseRef}]`, `reviewers: [{schemaKey: review_batch_schema, caseField: case, userField: assignee}]`,
`supervisorGroup: dossiq-coordinators` and `hideOthers: false`. An organisation that wants a blind
second review sets `hideOthers` on its Woo case type. The assessment writer
(`WOODocumentAssessmentService::bulkUpsert()`) SHALL apply REQ-CRA-003 and reconcile after a create.
An administrator runs `POST /api/cases/reviewer-access/reconcile` once after upgrading.

#### Scenario: The Woo case type declares it
- **GIVEN** an instance upgraded to this change
- **WHEN** the Woo case type is read
- **THEN** its `reviewerAccess.records` SHALL name `woo_assessment_schema` with case field `caseRef`

### Requirement: The redaction decisions on a Woo case's documents follow the same rule (REQ-WSA-007)

Once `openregister/reviewer-owns-their-decisions` is merged, dossiq's document schema (the one
`document_schema` names) SHALL declare
`x-openregister-review: {"ownership": "decider", "supervisors": ["dossiq-coordinators"], "hideOthers": <the Woo case type's reviewerAccess.hideOthers>}`,
and the key SHALL reach the installed instance.

#### Scenario: A second reviewer cannot change the first reviewer's redaction
- **GIVEN** the declaration on the document schema, and a name on document 7 redacted under 5.1.2e by `j.devries`
- **WHEN** `p.bakker` changes that redaction to release through OpenRegister's entity relation route
- **THEN** OpenRegister SHALL refuse it with `decision-owned`, naming `j.devries`
