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

### Requirement: The people assigned to the request are granted, and nobody else (REQ-WSA-002)

`OCA\Dossiq\Woo\WooAssessmentAccess::reconcile(string $caseId): array` SHALL make the grants of
every assessment of the case equal the assignment: owning group `dossiq-coordinators`, a read grant
for the case `assignee`, and a read grant for the `assignee` of every `wooReviewBatch` of the case
(when that schema exists), except where REQ-WSA-004 withholds one. It SHALL revoke every user grant
it did not just compute, SHALL NOT grant `update`, `delete` or `share`, and SHALL answer
`{status: 'reconciled'|'unavailable', granted: int, revoked: int, refused: int}`. It SHALL be
called after every assessment is created, after every batch assignment, and after every change of
the case `assignee`. It SHALL use `ObjectSharingService::grant()`, `revoke()` and `listGrants()`
and `ObjectOwnershipService::setOwnerGroup()` with their real signatures.

#### Scenario: The handler reads a reviewer's verdict
- **GIVEN** Woo case X with assignee `j.devries`, and reviewer `p.bakker` in a batch of X who assesses document 7
- **WHEN** `j.devries` reads the assessment of document 7
- **THEN** the read SHALL succeed, and `j.devries` SHALL hold a read grant on it and no update grant

#### Scenario: A coordinator reads and edits every assessment
- **GIVEN** the same assessment
- **WHEN** a member of `dossiq-coordinators` changes its classification
- **THEN** the change SHALL be stored

### Requirement: A reviewer edits only the assessments and redaction proposals they made (REQ-WSA-003)

`WOODocumentAssessmentService::bulkUpsert()` SHALL refuse, per document, a change to an existing
assessment whose owner is not the caller, unless the caller is a member of `dossiq-coordinators` or
an administrator. The refused document SHALL be reported in `errors` as
`{documentRef, errors: {assessment: 'owned-by-another-reviewer'}}`, with `assessedBy` and
`assessedAt` only when the caller may read them, and the stored assessment SHALL be unchanged.
`saveRedactionProposal()` and the redaction proposal review SHALL follow the same rule. The lookup
that finds whether a document already has an assessment SHALL run without RBAC and SHALL use only
the `documentRef` and uuid it returns, so a hidden assessment is never duplicated and never
disclosed.

#### Scenario: A second reviewer cannot overwrite the first
- **GIVEN** document 7 of Woo case X assessed `niet_openbaar` under 5.1.2e by `j.devries`
- **WHEN** reviewer `p.bakker`, assigned to X, posts `openbaar` for document 7
- **THEN** the response SHALL list document 7 under `errors` with `owned-by-another-reviewer`, and the assessment SHALL still read `niet_openbaar` by `j.devries`

#### Scenario: A hidden assessment is not assessed twice
- **GIVEN** hiding on and document 7 assessed by `j.devries`
- **WHEN** `p.bakker`, who cannot read that assessment, posts a verdict for document 7
- **THEN** no second assessment for document 7 SHALL exist, and the error SHALL carry neither `assessedBy` nor the classification

### Requirement: An organisation can bar a reviewer from another reviewer's verdicts (REQ-WSA-004)

The setting `woo_review_hide_others` SHALL default to off. When it is on, `reconcile()` SHALL NOT
grant a batch reviewer read on an assessment another user made. The case assignee and the
coordinators SHALL keep their access. `getOutstanding()` SHALL NOT list a document that has an
assessment the caller cannot read, and the case's Woo assessment section SHALL show such a document
as assessed by another reviewer, without classification, grounds or author.

#### Scenario: A blind second review
- **GIVEN** hiding on, Woo case X with ten documents in one batch for `p.bakker`, four of them already assessed by `j.devries`
- **WHEN** `p.bakker` opens the case's Woo assessment section
- **THEN** all ten documents SHALL be listed, the four SHALL show as assessed by another reviewer without their verdict, and the outstanding count SHALL be six

#### Scenario: Off by default
- **GIVEN** a fresh install
- **WHEN** a batch reviewer of Woo case X reads the assessments of X
- **THEN** they SHALL read every assessment of X

### Requirement: A Woo case changes hands only through a coordinator (REQ-WSA-005)

When an update changes the `assignee` of a Woo case that has assessments, dossiq SHALL refuse it
before it is saved, with `{error: 'woo-reassign-needs-coordinator'}`, unless the caller is a member
of `dossiq-coordinators` or an administrator. After an accepted change, `reconcile()` SHALL run, so
the former assignee's grants are revoked and the new assignee's granted. A case without assessments
SHALL change hands as today.

#### Scenario: The former handler loses access
- **GIVEN** Woo case X with assessments and assignee `j.devries`
- **WHEN** a coordinator sets the assignee to `a.smit`
- **THEN** `a.smit` SHALL read X's assessments and `j.devries` SHALL read none they did not make

#### Scenario: A handler cannot hand the case on alone
- **GIVEN** the same case
- **WHEN** `j.devries`, who is not a coordinator, sets the assignee to `a.smit`
- **THEN** the update SHALL be refused with `woo-reassign-needs-coordinator`, and the case and the grants SHALL be unchanged

### Requirement: Existing assessments are reconciled by an administrator (REQ-WSA-006)

`POST /api/woo/access/reconcile`, administrator only, SHALL run `reconcile()` for every Woo case
and answer the summed counts and the cases it could not reconcile. Until it has run, an existing
assessment SHALL be readable only by its author and administrators. When `ObjectSharingService`
does not resolve, the route SHALL answer `unavailable` and write nothing.

#### Scenario: One run after the upgrade
- **GIVEN** an upgraded instance with two Woo cases and five assessments made before the upgrade
- **WHEN** an administrator posts to `/api/woo/access/reconcile`
- **THEN** each case's assignee SHALL read its assessments, and a second run SHALL answer zero granted and zero revoked

### Requirement: The redaction decisions on a Woo case's documents follow the same rule (REQ-WSA-007)

Once `openregister/reviewer-owns-their-decisions` is merged, dossiq's document schema (the one
`document_schema` names) SHALL declare
`x-openregister-review: {"ownership": "decider", "supervisors": ["dossiq-coordinators"], "hideOthers": <woo_review_hide_others>}`,
and the key SHALL reach the installed instance.

#### Scenario: A second reviewer cannot change the first reviewer's redaction
- **GIVEN** the declaration on the document schema, and a name on document 7 redacted under 5.1.2e by `j.devries`
- **WHEN** `p.bakker` changes that redaction to release through OpenRegister's entity relation route
- **THEN** OpenRegister SHALL refuse it with `decision-owned`, naming `j.devries`
