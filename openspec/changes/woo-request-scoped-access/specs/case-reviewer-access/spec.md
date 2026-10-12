# case-reviewer-access

Generic capability (decision 182: procedures are configuration, code is generic). A case type
declares which of its records are private to the people on the case; one reconciler applies any
declaration through OpenRegister's private scope and per-object grants. The Woo request's private
assessments are configuration on the Woo case type (see `woo-request-scoped-access`), not code.

## ADDED Requirements

### Requirement: A case type declares the records it keeps private to the case (REQ-CRA-001)

The case type schema SHALL carry `reviewerAccess` with `records` (a list of `{schemaKey,
caseField}`: the app setting that names the record's schema and the field holding the case),
`reviewers` (a list of `{schemaKey, caseField, userField}`: where the case's reviewers are named),
`supervisorGroup` (default `dossiq-coordinators`) and `hideOthers` (default false). A record schema
named there SHALL carry `scope: private` in its own authorization block, so OpenRegister admits only
its owner, administrators, its owning group and its grantees. A case type without `records` SHALL
behave as before: nothing reconciled, every record changeable as before.

#### Scenario: An undeclared case type is untouched
- **GIVEN** a case type without `reviewerAccess`
- **WHEN** its case is reconciled
- **THEN** the answer SHALL be `not-declared` and no grant SHALL be written

### Requirement: The people on the case are granted read, and nobody else (REQ-CRA-002)

`CaseReviewerAccess::reconcile(caseId)` SHALL make every declared record of the case owned by the
supervising group and readable by the case `assignee` and every reviewer the `reviewers` sources
name, except where `hideOthers` withholds the reviewers. Through `ObjectReadGrants` it SHALL grant
only core's read bit, never grant the record's owner, revoke every non-inherited user grant it did
not compute, leave group and inherited grants alone, and answer `{status: reconciled | not-declared
| unavailable, granted, revoked, refused}`. A refusal by OpenRegister SHALL be counted, never
reported as success. Without OpenRegister's sharing services it SHALL answer `unavailable` and
write nothing. A second run SHALL grant and revoke nothing. It SHALL run after a declared record is
created.

#### Scenario: The handler reads a reviewer's record
- **GIVEN** a case with assignee `j.devries` and a batch naming reviewer `p.bakker`, who made record 7
- **WHEN** the case is reconciled
- **THEN** `j.devries` SHALL hold a read grant on record 7, `p.bakker` none (the owner), and nobody an update grant

#### Scenario: Hidden reviewers
- **GIVEN** `hideOthers` on
- **WHEN** the case is reconciled
- **THEN** only the assignee SHALL be granted; each reviewer reads only their own records

### Requirement: A reviewer changes only the records they made (REQ-CRA-003)

`CaseReviewerAccess::mayChange(caseId, record, userId)` SHALL answer true only for the record's
owner, a member of the supervising group or an administrator, on a case type that declares
reviewer access. A writer of a declared record SHALL refuse a change by anyone else, per record, with
`owned-by-another-reviewer`, naming neither the author nor the stored content, and SHALL find an
existing record without RBAC so a record the caller cannot see is never created twice.

#### Scenario: A second reviewer cannot overwrite the first
- **GIVEN** record 7 made by `j.devries`
- **WHEN** reviewer `p.bakker` writes record 7 again
- **THEN** the write SHALL be refused with `owned-by-another-reviewer` and record 7 SHALL be unchanged

### Requirement: Existing records are reconciled by an administrator (REQ-CRA-004)

`POST /api/cases/reviewer-access/reconcile`, administrator only (fails closed), SHALL reconcile every
case of every case type that declares reviewer access and answer the summed counts and the cases
with a refusal; 503 with `unavailable` when OpenRegister has no per-object sharing.

#### Scenario: A non-administrator
- **WHEN** a coordinator who is no administrator posts to the route
- **THEN** the answer SHALL be 403 and nothing SHALL be reconciled

### Requirement: A case with private records changes hands only through a supervisor (REQ-CRA-005)

When an update changes the `assignee` of a case whose case type declares reviewer access and that
has declared records, dossiq SHALL refuse it before it is saved, with
`{error: 'reassign-needs-supervisor'}`, unless the caller is in the supervising group or an
administrator. After an accepted change the case SHALL be reconciled, so the former assignee's
grants are revoked and the new assignee's granted. A case without declared records SHALL change
hands as before.

#### Scenario: A handler cannot hand the case on alone
- **GIVEN** a case with private records and assignee `j.devries`, who is no supervisor
- **WHEN** `j.devries` sets the assignee to `a.smit`
- **THEN** the update SHALL be refused with `reassign-needs-supervisor` and the case and the grants SHALL be unchanged
