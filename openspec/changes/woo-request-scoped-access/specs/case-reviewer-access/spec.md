# case-reviewer-access

Generic capability (decision 182: procedures are configuration, code is generic). A case type
declares which of its records are private to the people on the case; one reconciler applies any
declaration through OpenRegister's private scope and per-object grants. The Woo request's private
assessments are configuration on the Woo case type (see `woo-request-scoped-access`), not code.

## ADDED Requirements

### Requirement: A case type declares the records it keeps private to the case (REQ-CRA-001)

The case type schema SHALL carry `reviewerAccess` with `records` (a list of `{schemaKey,
caseField}`: the app setting that names the record's schema and the field holding the case, with
the optional reader rule of REQ-CRA-008),
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
name, except where `hideOthers` withholds the reviewers or the record's own `readers` rule
(REQ-CRA-008) narrows it. Through `ObjectReadGrants` it SHALL grant
only core's read bit, unless the record's entry says a reader may update it (REQ-CRA-010), never grant the record's owner, revoke every non-inherited user grant it did
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

### Requirement: A case type says per record who on the case reads it (REQ-CRA-008)

Each entry of `reviewerAccess.records` MAY carry `readers`: `all` (the default: the case assignee
and every reviewer the `reviewers` sources name, unless `hideOthers` withholds them), `assignee`
(the case assignee only) or `own` (the case assignee and the reviewers the record itself names).
With `own` the entry names where the record holds them: `userField`, a field holding user ids, or
`reviewerRowField`, a field holding the id of a row of one of the `reviewers` sources, whose
reviewers then read the record. A row of another case SHALL name nobody. The supervising group owns
the record under all three. A value other than these three SHALL be read as `assignee`, the narrow
side. `CaseRecordReaders` computes the readers of one record; `CaseReviewerAccess::reconcile()`
SHALL ask it per record, so narrowing a rule and reconciling revokes the grants that no longer hold.

#### Scenario: A reviewer reads only the batch that names them
@e2e exclude Grant computation over a declaration, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testAReviewerReadsOnlyTheRecordThatNamesThem; the live read is task 6.4 of woo-review-triage (live pass, decision 139).
- **GIVEN** a case with assignee `j.devries`, batch 1 naming reviewer `p.bakker`, batch 2 naming `a.smit`, and the batch record declared `readers: own`, `userField: assignee`
- **WHEN** the case is reconciled
- **THEN** batch 1 SHALL be read by `j.devries` and `p.bakker`, batch 2 by `j.devries` and `a.smit`, and neither reviewer SHALL hold a grant on the other's batch

#### Scenario: A record for the assignee only
@e2e exclude Grant computation over a declaration, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testARecordSharedWithTheAssigneeOnlyReachesNoReviewer.
- **GIVEN** the same case and a record declared `readers: assignee`
- **WHEN** the case is reconciled
- **THEN** only `j.devries` SHALL hold a read grant on it, and a grant a reviewer held before SHALL be revoked

#### Scenario: A record that belongs to a batch
@e2e exclude Grant computation over a declaration, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testARecordOfABatchIsReadByThatBatchsReviewer.
- **GIVEN** a record declared `readers: own`, `reviewerRowField: batch`, one row naming batch 2 and one naming no batch
- **WHEN** the case is reconciled
- **THEN** the first SHALL be read by `j.devries` and `a.smit`, the second by `j.devries` alone

### Requirement: A case type says per record who on the case may update it (REQ-CRA-010)

Each entry of `reviewerAccess.records` MAY carry `assigneeMay` and `reviewersMay`, each `read` (the
default) or `update`. With `assigneeMay: update` the case assignee SHALL be granted core's read and
update bits on the record. With `reviewersMay: update` the reviewers who read the record under its
`readers` rule (REQ-CRA-008) SHALL be granted both bits; a reviewer who does not read the record
SHALL gain nothing. An assignee who is also such a reviewer SHALL get the wider of the two. Any
other value SHALL be read as `read`, the narrow side. Delete and share SHALL never be granted.
`CaseRecordReaders::toGrant()` computes the bits per reader and `ObjectReadGrants::reconcile()`
SHALL make the record's user grants equal them: a grant that carries other permissions than its
reader is due SHALL be revoked and granted again, so widening or narrowing an entry and reconciling
reaches the records that already exist. A second run SHALL grant and revoke nothing.

#### Scenario: A reviewer updates the records of their own batch
@e2e exclude Grant computation over a declaration, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testAReviewerMayUpdateTheRecordsOfTheirOwnBatch; the live update is task 6.4 of woo-review-triage (live pass, decision 139).
- **GIVEN** a case with assignee `j.devries`, batch 2 naming reviewer `a.smit`, and a record declared `readers: own`, `reviewerRowField: batch`, `reviewersMay: update`
- **WHEN** the case is reconciled
- **THEN** `a.smit` SHALL hold read and update on the record of batch 2, `j.devries` read only, and nobody delete or share

#### Scenario: A grant that is too narrow is replaced
@e2e exclude Grant reconciliation against a sharing double, covered by tests/Unit/Service/Access/ObjectReadGrantsTest.php testAGrantWithOtherPermissionsThanDueIsGrantedAgain.
- **GIVEN** a record on which `a.smit` holds a read grant and the entry now says `reviewersMay: update`
- **WHEN** the case is reconciled
- **THEN** the read grant SHALL be revoked and `a.smit` SHALL be granted read and update

#### Scenario: An unknown value grants read only
@e2e exclude Normalisation of a declaration, covered by tests/Unit/Service/Access/CaseRecordReadersTest.php testAnythingButUpdateInFullIsRead.
- **GIVEN** an entry with `assigneeMay: write`
- **WHEN** the case is reconciled
- **THEN** the assignee SHALL hold a read grant only

### Requirement: A rule of the case reads the whole case (REQ-CRA-011)

A private record is read by a slice of the people on the case, so a search under the caller's own
access answers only that slice. A rule that holds for the case SHALL NOT read it that way. Such a
rule (what still holds up the decision, which required pages nobody saw, which documents a rule may
mark, whether a document already has a record) SHALL read every record of the case regardless of
the caller's grants, and SHALL keep only the records that name that case. `CaseReviewerAccess::recordsOf()`
is that read; `DocumentRelevance::forCase()` makes the same read for the reviews. A writer that
looks up a record before it writes SHALL use this read, so it never makes a second record for a
document whose record the caller cannot read. The write itself SHALL run under the caller's own
access: a caller without an update grant is refused by OpenRegister. A route that reaches such a
rule SHALL check access to the case first and SHALL NOT answer a record's row from this read; a
person lists records through OpenRegister, under their own access.

A writer that creates a declared record SHALL call `CaseReviewerAccess::reconcileAfterCreate()` in
the same request. The record is stored by then, so a reconcile that cannot run SHALL be logged and
answered as `failed`, SHALL NOT undo the write, and SHALL leave the record without grants.

#### Scenario: A batch reviewer marks a document they were not given
@e2e exclude Read and write over an object service double, covered by tests/Unit/Review/DocumentRelevanceTest.php testMarkingChangesTheReviewTheCallerCannotRead and tests/Unit/Controller/DocumentReviewControllerTest.php testAReviewTheCallerCannotReadIsNotMadeTwiceAndStillCounts; the live refusal is task 6.4 of woo-review-triage (live pass, decision 139).
- **GIVEN** a case with a review on document 9 that `p.bakker` holds no grant on
- **WHEN** `p.bakker` marks document 9
- **THEN** the write SHALL address the review that exists, and no second review SHALL be made for document 9

#### Scenario: The decision waits for a page on a review the caller cannot read
@e2e exclude Rule over an object service double, covered by tests/Unit/Review/PagesSeenTest.php testTheDecisionWaitsForPagesOnReviewsTheCallerCannotRead.
- **GIVEN** an in-scope document with required page 7 unseen, on a review the caller holds no grant on
- **WHEN** the caller asks whether every required page was seen
- **THEN** the answer SHALL be the refusal `review-pages-unseen`

#### Scenario: A reconcile that cannot run keeps the write
@e2e exclude Failure path over a sharing double, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testAReconcileThatCannotRunAfterAWriteIsAnsweredAsFailedAndKeepsTheWrite.
- **GIVEN** a writer that stored two records and a sharing service that throws
- **WHEN** it calls `reconcileAfterCreate()`
- **THEN** the answer SHALL be `{status: failed, refused: 2}` and no exception SHALL reach the writer

### Requirement: A reader is somebody the register admits (REQ-CRA-009)

OpenRegister keeps a record's schema as the ceiling of a grant, and the dossiq register is closed to
everybody outside `dossiq-medewerkers` (case-access-control REQ-ACC-11). So a grant to a person
outside that group changes nothing: they still read no record. `CaseReviewerAccess::isAdmittedReader(userId)`
SHALL answer true only for a member of the staff group or an administrator, and a writer that names
a person as a reviewer SHALL ask it first and refuse with a sentence that names the group.

#### Scenario: A supervisor outside the staff group
@e2e exclude A group membership answer, covered by tests/Unit/Service/Access/CaseReviewerAccessTest.php testOnlyStaffAndAdministratorsCanBeNamedAsReaders.
- **GIVEN** a member of `dossiq-coordinators` who is no member of `dossiq-medewerkers` and no administrator
- **WHEN** `isAdmittedReader` is asked about them
- **THEN** the answer SHALL be false

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
with a refusal; 503 with `unavailable` when OpenRegister has no per-object sharing. It runs the
walk the upgrade runs (REQ-CRA-012), so it is the fallback for an upgrade that said it did not
finish. Its `status` SHALL be `incomplete`, never `reconciled`, when a case is in `failed` or a
case type in `unfinished`.

#### Scenario: A non-administrator
- **WHEN** a coordinator who is no administrator posts to the route
- **THEN** the answer SHALL be 403 and nothing SHALL be reconciled

### Requirement: Existing records are reconciled on upgrade (REQ-CRA-012)

A record made before its schema became private, or before its case type changed who reads it,
carries no grant or the wrong one, and the people on its case are refused it. An upgrade SHALL
therefore reconcile every case of every case type that declares reviewer access, in the repair
step `ReconcileCaseRecordGrants`, registered last in `post-migration`. No instance SHALL depend on
an administrator calling a route after the upgrade.

The walk (`CaseRecordGrantsBackfill::run()`) SHALL ask `CaseReviewerAccess::reconcile()` per case,
SHALL read the cases a page at a time, and SHALL read every record of a case, however many. It
SHALL be safe to run again: a record that already carries the right grants is left alone.

The walk SHALL read the case types itself, a page at a time and regardless of the caller, and
SHALL NOT use the shared case type listing: that listing answers one page and answers nothing
when its read fails, and a walk over nothing would report every case reconciled. A case type read
that fails SHALL stop the run as an error, said like every other unfinished run.

OpenRegister writes a grant only for a signed-in owner, owning group member or administrator, and
an upgrade has nobody signed in. The walk SHALL run as the signed-in administrator when there is
one, and otherwise as the first enabled account of the `admin` group in order of user id, SHALL
put the session back when it ends, and SHALL name that account in its answer.

Decision 222 (12 Oct) replaces that administrator account with dossiq's background service
account. It is not built: OpenRegister has no right that lets an account that is neither owner
nor administrator write a grant (Q-dossiq-L8-4). Until OpenRegister has one, this paragraph
describes what runs.

The step SHALL never throw and SHALL never report more than it did. A case that threw, a record
OpenRegister refused, a case type with more cases than one run walks (50,000), no enabled
administrator and no per-object sharing SHALL each be said as a warning in the upgrade output and
as an error in the log, with the counts, up to twenty case ids and the route of REQ-CRA-004.

#### Scenario: A review made by an earlier upgrade
@e2e exclude Upgrade step over the shipped register with a sharing double, covered by tests/Unit/Repair/ReconcileCaseRecordGrantsTest.php testTheRegisteredStepGivesAnExistingReviewItsGrantsWithNobodySignedIn; the upgrade on a running instance is task 6.4 of woo-review-triage (live pass, decision 139).
- **GIVEN** a case with assignee `j.devries`, a batch of `p.bakker` and a review in that batch with no owner and no grant
- **WHEN** the instance is upgraded with nobody signed in
- **THEN** `j.devries` and `p.bakker` SHALL read and update the review, and the output SHALL say the counts and the administrator account it ran as

#### Scenario: The upgrade runs twice
@e2e exclude Idempotency over a sharing double, covered by tests/Unit/Repair/ReconcileCaseRecordGrantsTest.php testRunningTheRegisteredStepTwiceChangesNothingTheSecondTime.
- **WHEN** the step runs a second time
- **THEN** it SHALL add and revoke no grant

#### Scenario: A case that could not be reconciled
@e2e exclude Failure path over a reconcile double, covered by tests/Unit/Service/Access/CaseRecordGrantsBackfillTest.php testACaseThatThrowsIsNamedAndTheWalkGoesOn and tests/Unit/Repair/ReconcileCaseRecordGrantsTest.php testAnUnfinishedRunIsAWarningAndAnErrorNeverASuccess.
- **GIVEN** a case whose reconcile throws
- **WHEN** the step runs
- **THEN** the other cases SHALL still be reconciled, and the output SHALL be a warning that names the case, never a success

#### Scenario: The case types could not be read
@e2e exclude Failure path over a store double, covered by tests/Unit/Service/Access/CaseRecordGrantsBackfillTest.php testACaseTypeReadThatFailsIsNotARunOverNoCases and testACaseTypeBeyondTheFirstPageOfCaseTypesIsStillWalked.
- **GIVEN** a store that fails the read of the case types
- **WHEN** the step runs
- **THEN** the output SHALL be a warning that the reconcile did not finish, never "0 cases reconciled", and the session SHALL be put back

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

### Requirement: A decision on a case's file is changed by its decider or a supervisor of that case (REQ-CRA-006)

When OpenRegister asks, through its vetoable `DecisionChangeRequestedEvent`, whether a user other
than the decider may change a decision (decision 197: a decision is its own object), dossiq SHALL
map the decision's subject object to the case it belongs to: the case itself when the object is a
case, or every case that links the object as a case document. When at least one of those cases has
a case type that declares reviewer access, dossiq SHALL allow the change only for a member of that
case type's supervising group or an administrator, and refuse it otherwise with
`case-supervisor-required`; one refusing case refuses. When no such case exists dossiq SHALL leave
OpenRegister's answer as it was. A read that throws SHALL refuse.

#### Scenario: A second reviewer cannot change the first reviewer's redaction
- **GIVEN** a document linked to a Woo case, whose case type declares reviewer access with supervising group `dossiq-coordinators`, and a redaction decided by `j.devries`
- **WHEN** `p.bakker`, who is no coordinator, asks to change it
- **THEN** dossiq SHALL refuse with `case-supervisor-required`

#### Scenario: A coordinator may
- **WHEN** a member of `dossiq-coordinators` asks to change the same decision
- **THEN** dossiq SHALL allow it

#### Scenario: A document on no guarded case
- **GIVEN** a document linked to no case, or only to cases whose case type declares no reviewer access
- **THEN** dossiq SHALL leave OpenRegister's answer unchanged

### Requirement: A supervisor of the case sees a hidden decision on its file (REQ-CRA-007)

When OpenRegister asks, through `DecisionVisibilityRequestedEvent` (openregister REQ-DEC-003),
whether a user may see another reviewer's decision that a schema hides under `hideOthers`, dossiq
SHALL map the decision's subject object to its cases as in REQ-CRA-006 and allow it only when at
least one of those cases has a case type that declares reviewer access and the user supervises
every such case (a member of its case type's supervising group, or an administrator). Otherwise
dossiq SHALL not answer, so the decision stays hidden. A read that throws SHALL leave it hidden.

#### Scenario: A case-type supervisor outside the schema's groups sees the hidden decision
- **GIVEN** `hideOthers` on, a document linked to a case whose case type names supervising group `woo-coordinators`, which the document schema does not name, and a redaction decided by `j.devries`
- **WHEN** a member of `woo-coordinators` reads the document's detections
- **THEN** dossiq SHALL allow it and the decision, its ground and its verdict are shown

#### Scenario: Anyone else, or a failed read, keeps it hidden
- **WHEN** `p.bakker`, who supervises no case of the document, reads them, or the case cannot be read
- **THEN** dossiq SHALL not allow it and the relation shows `decision: "hidden"`
