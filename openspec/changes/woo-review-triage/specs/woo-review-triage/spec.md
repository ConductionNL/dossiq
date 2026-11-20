## ADDED Requirements

### Requirement: The Woo-verzoek case type enables document review (REQ-WRT-W01)

The relevance marking, triage rules, review batches, review depth, pages seen, stopping rule and
recall estimate of a Woo-verzoek are the generic document review capability (`document-review`,
REQ-WRT-001 to REQ-WRT-005 and REQ-WRS-001 to REQ-WRS-003). This requirement holds only the Woo
configuration (decision 182): the seeded `woo-verzoek` case type SHALL set
`documentReview.enabled` true. Its `documentReview.reviewDepth` SHALL be empty in the seed, so every
document type reads as every page until an administrator names a sampled type on the case type.
For this case type the verdict of the capability is `wooDocumentAssessment.classification`, its
bulk route is `bulkAssess`, and its closing steps are `WOODecisionService::assembleDecision()` and
`OutcomePublisher::publish()`.

#### Scenario: A Woo-verzoek reviews its documents through the generic capability
- **GIVEN** the seeded `woo-verzoek` case type and a case of that type with documents
- **WHEN** the handler opens the case
- **THEN** the case type SHALL read `documentReview.enabled` true, and the case SHALL show the review sections of the `document-review` capability

### Requirement: A Woo request is an authorisation container (REQ-WRT-006)

Access to a Woo case's records SHALL follow the case through per-object grants that dossiq sets,
the mechanism of `dossiq/woo-request-scoped-access`. That change owns `wooDocumentAssessment`
(REQ-WSA-001 to REQ-WSA-006) and this requirement does not restate it. This requirement covers the
other seven records of a Woo case: `documentReview`, `reviewBatch`, `triageRule`, `wooExclusion`,
`wooSearchPlan`, `wooCollectionQuery` and `wooDeliveredSet`.

None of the eight SHALL declare `x-openregister-hierarchy`. OpenRegister's built
`rbac-inherits-to-children` accepts as hierarchy parent only a property that references the same
schema (`HierarchyAnnotationValidator::checkParentProperty()`, code `hierarchy.foreign-reference`,
called from `SchemaMapper::validateHierarchyAnnotation()`), so a parent that points at the case, or
at a `reviewBatch`, is refused at save and the whole schema fails to import. The case schema's
own `parentCase` edge stays as it is; it is case to case and does not reach these records.

Each of the seven SHALL carry the authorization block
`{"scope": "private", "read": [staff], "create": [staff], "update": [staff], "delete": ["dossiq-coordinators"]}`,
where staff is `dossiq-medewerkers` and `dossiq-background-service`, the two groups the dossiq
register itself is closed to (case-access-control REQ-ACC-11; `RegisterAccessDeclaredTest` refuses
`authenticated` on any schema). The register version SHALL be raised so it reaches an installed
instance. No schema of the seven SHALL grant read without `scope` `private`. OpenRegister keeps the
schema block as the ceiling of a grant, so a reviewer named by a batch reads it only as a member of
`dossiq-medewerkers`.

`OCA\Dossiq\Woo\WooAssessmentAccess::reconcile(string $caseId): array` SHALL also make the grants
on every record of the seven that names the case equal this table, with owning group
`dossiq-coordinators` on each, and SHALL revoke every user grant it did not just compute:

| record | case assignee | reviewer named by a `reviewBatch` of the case |
| --- | --- | --- |
| `documentReview` | read, update | read, update, only on the reviews of their own batch |
| `reviewBatch` | read, update | read, only on their own batch |
| `triageRule` | read, update | none |
| `wooExclusion` | read, update | none |
| `wooSearchPlan` | read, update | none |
| `wooCollectionQuery` | read, update | none |
| `wooDeliveredSet` | read | none |

The read column of this table is configuration, not code (decision 182): the `woo-verzoek` case
type lists each record under `reviewerAccess.records` with the generic reader rule of
case-reviewer-access REQ-CRA-008, `readers: own` with `userField: assignee` for `reviewBatch`,
`readers: own` with `reviewerRowField: batch` for `documentReview`, and `readers: assignee` for the
others. `CaseReviewerAccess::reconcile()` is the reconciler; `WooAssessmentAccess` was never built.

It SHALL NOT grant `delete` or `share` on any of them. Its answer keeps the shape REQ-WSA-002
defines, `{status, granted, revoked, refused}`, with the seven counted in.

`reconcile()` runs in the request of the user who acted, never in a background job: OpenRegister's
`ObjectSharingService::grant()`, `revoke()` and `ObjectOwnershipService::setOwnerGroup()` throw
`NotAuthorizedException` unless the caller is the object's owner, an administrator or a member of
its owning group, and OpenRegister has no system path to set a grant. A grant the caller may not
set SHALL be counted in `refused`, SHALL NOT be retried, and SHALL leave that record without the
grant, which is the safe state. The case page SHALL show the number of records still waiting for a
coordinator. Every dossiq route that creates one of the seven SHALL call `reconcile()` for the case
after the create, in the same request: the gather add, the search plan save, the query record, the
exclusion route, the rule create, the batch create and `OutcomePublisher::publish()`. The
repair step of REQ-WRT-001 runs without a user and SHALL NOT call it; the records it creates get
their grants from the last step of the same upgrade, which reconciles every declaring case as an
administrator account (case-reviewer-access REQ-CRA-012). The administrator route of REQ-WSA-006
runs that same walk and is the fallback; both reconcile the seven as well.

Every dossiq route on these records SHALL also check access through `CaseAccessGuard` for the case.

#### Scenario: A reviewer scoped to one request sees only that request
- **GIVEN** Woo cases X and Y in the same organisation, reviewer A named by a batch of X only, and a coordinator who created that batch
- **WHEN** A lists `documentReview` objects through dossiq's routes and through the OpenRegister API
- **THEN** both SHALL answer the reviews of A's batch in X and none of Y's

#### Scenario: No grant, no read
- **GIVEN** an authenticated user who is not the assignee of any Woo case, is named by no batch and is not a coordinator
- **WHEN** they read Woo case X's `triageRule`, `wooSearchPlan` and `wooDeliveredSet` objects through either path
- **THEN** both paths SHALL refuse or list nothing

#### Scenario: No Woo record declares a hierarchy, so every one imports
- **GIVEN** dossiq's register as the importer reads it
- **WHEN** an instance imports it
- **THEN** none of the eight Woo schemas SHALL carry `x-openregister-hierarchy`, each of the seven SHALL read back with `authorization.scope` `private`, and the import SHALL report no failed schema

#### Scenario: A grant the caller may not set is reported, not retried
- **GIVEN** a `documentReview` of case X owned by user C, and handler H, who is not a coordinator, creating a batch for reviewer R that includes it
- **WHEN** the batch is created
- **THEN** the batch SHALL exist, `reconcile()` SHALL answer `refused` 1, R SHALL NOT read that review, and the case page SHALL show one record waiting for a coordinator
- **AND** when a coordinator then runs the reconcile for X, R SHALL read it

#### Scenario: A change of hands moves the grants on every record
- **GIVEN** Woo case X with a search plan, two triage rules and a batch, and assignee `j.devries`
- **WHEN** a coordinator sets the assignee to `a.smit` (REQ-WSA-005)
- **THEN** `a.smit` SHALL read and update those records and `j.devries` SHALL read none of them
