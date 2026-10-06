## ADDED Requirements

### Requirement: Relevance is marked apart from the verdict, and reported (REQ-WRT-001)

Each document collected on a Woo case SHALL have one `wooDocumentReview` with `case`, `documentRef`,
`relevance` (`unmarked`, `in-scope`, `out-of-scope`), `relevanceSource` (`reviewer` or `rule`),
`rule` (when a rule decided), `markedBy`, `markedAt`, `batch`, `depth`, `pageCount`, `pagesRequired`
and `pagesSeen`. Only `in-scope` documents SHALL need a disclosure verdict;
`WOODocumentAssessmentService::getOutstanding()` SHALL list in-scope documents without a verdict
and unmarked documents, and SHALL NOT list out-of-scope ones. The Woo case summary SHALL report
`{unmarked, inScope, outOfScope}` beside the verdict counts.

#### Scenario: Out of scope needs no verdict, and both counts are reported
- **GIVEN** a Woo case with 10 documents: 6 marked in scope, 3 out of scope, 1 unmarked, and 4 in-scope ones assessed
- **WHEN** the case summary is read
- **THEN** it SHALL report `inScope` 6, `outOfScope` 3, `unmarked` 1 and verdicts for 4
- **AND** the outstanding list SHALL hold the 2 unassessed in-scope documents and the unmarked one

### Requirement: Rules mark documents without opening them, visibly (REQ-WRT-002)

A Woo case SHALL hold `wooTriageRule` objects with `name`, `conditions` (all of: `custodian`,
`sourceSystem`, `mimeType`, `dateFrom`, `dateTo`, `senderDomain`, `textContains`,
`textNotContains`), `effect` (`in-scope` or `out-of-scope`), `order` and `active`. POST
`/api/cases/{id}/woo/triage/apply` SHALL evaluate the active rules in order on every `unmarked`
document, mark the first match with its effect, set `relevanceSource` `rule` and `rule`, and answer
the count per rule. A rule SHALL NOT change a document a reviewer marked. A reviewer MAY change a
rule's marking; the review SHALL then hold `relevanceSource` `reviewer` and `overturnedRule`. The case
page SHALL list the rules with their conditions and the number of documents each decided.

#### Scenario: A rule sets aside newsletters
- **GIVEN** a rule "Nieuwsbrieven" with `senderDomain` "nieuwsbrief.example.nl" and effect `out-of-scope`, and 5 unmarked mails from that domain
- **WHEN** the handler applies the rules
- **THEN** the 5 mails SHALL be `out-of-scope` with `rule` naming "Nieuwsbrieven", and none was opened

#### Scenario: A reviewer overturns a rule
- **GIVEN** a mail marked out of scope by "Nieuwsbrieven"
- **WHEN** a reviewer marks it in scope
- **THEN** it SHALL be `in-scope` with `relevanceSource` `reviewer` and `overturnedRule` "Nieuwsbrieven"
- **AND** applying the rules again SHALL leave it in scope

### Requirement: Batches are assigned to named reviewers before any verdict (REQ-WRT-003)

POST `/api/cases/{id}/woo/batches` SHALL create a `wooReviewBatch` with `name`, `documents` (a list,
or a filter on custodian, source system or rule), and `assignee` (a Nextcloud user). It SHALL write
the batch on each listed review, and SHALL create a dossiq task on the case assigned to that reviewer,
naming the batch. A document SHALL be in at most one open batch. The batch, its assignee and its
progress (assessed of total) SHALL be visible on the case before any verdict is made.

#### Scenario: Two batches for two reviewers
- **GIVEN** 40 in-scope documents
- **WHEN** the handler creates batch "Mail 2025" with 25 documents for reviewer A and batch "Notities" with 15 for reviewer B
- **THEN** A and B SHALL each have a task naming their batch, and the case SHALL show both batches at 0 of 25 and 0 of 15

#### Scenario: A document cannot be in two open batches
- **GIVEN** a document in batch "Mail 2025"
- **WHEN** the handler adds it to another batch
- **THEN** the add SHALL be refused, naming the batch it is in

### Requirement: Review depth is set per document type and recorded (REQ-WRT-004)

`wooRequestConfiguration.reviewDepth` SHALL map a document type (the informatieobjecttype, or a mime
class when there is none) to `{mode: 'every-page'|'sample', sampleSize}`, with `every-page` as the
default for any type it does not name. When a batch is created, each review SHALL get `depth` (the
mode and size that applied) and `pagesRequired`: every page for `every-page`, or the sampled page
numbers, drawn once with a recorded seed, for `sample`. `wooRequestConfiguration` SHALL copy
`reviewDepth` with the rest of the configuration (REQ-WRC-005).

#### Scenario: Sampling large spreadsheets exports
- **GIVEN** a depth of `sample` with size 5 for type "export" and a 200-page export in a new batch
- **WHEN** the batch is created
- **THEN** that review SHALL record depth `sample` 5 and five `pagesRequired` with the seed
- **AND** a 3-page letter in the same batch SHALL record `every-page` and pages 1 to 3

### Requirement: Nothing is decided or published before the required pages are seen (REQ-WRT-005)

The review viewer SHALL report the pages a reviewer displayed to POST
`/api/cases/{id}/woo/documents/{documentRef}/pages-seen` with `{pages: [...]}`, which SHALL add them
to `pagesSeen` with the reviewer and time. A verdict on an in-scope document SHALL be refused,
through `bulkAssess` and through a direct OpenRegister write, while any of its `pagesRequired` is
not in `pagesSeen`. `WOODecisionService::assembleDecision()` and `WooPublicationService::publish()`
SHALL refuse with 409 while any in-scope document has unseen required pages, naming the documents.

#### Scenario: A verdict set without opening is refused
- **GIVEN** an in-scope 3-page document with no pages seen
- **WHEN** a handler PATCHes its assessment to `openbaar` through the OpenRegister API
- **THEN** the write SHALL be refused, naming the unseen pages 1 to 3

#### Scenario: The publishable set waits for the last page
- **GIVEN** every in-scope document assessed, but page 7 of one document unseen
- **WHEN** the handler publishes
- **THEN** publish SHALL answer 409 naming that document and page 7

### Requirement: A Woo request is an authorisation container (REQ-WRT-006)

Access to a Woo case's records SHALL follow the case through per-object grants that dossiq sets,
the mechanism of `dossiq/woo-request-scoped-access`. That change owns `wooDocumentAssessment`
(REQ-WSA-001 to REQ-WSA-006) and this requirement does not restate it. This requirement covers the
other seven Woo records: `wooDocumentReview`, `wooReviewBatch`, `wooTriageRule`, `wooExclusion`,
`wooSearchPlan`, `wooCollectionQuery` and `wooDeliveredSet`.

None of the eight SHALL declare `x-openregister-hierarchy`. OpenRegister's built
`rbac-inherits-to-children` accepts as hierarchy parent only a property that references the same
schema (`HierarchyAnnotationValidator::checkParentProperty()`, code `hierarchy.foreign-reference`,
called from `SchemaMapper::validateHierarchyAnnotation()`), so a parent that points at the case, or
at a `wooReviewBatch`, is refused at save and the whole schema fails to import. The case schema's
own `parentCase` edge stays as it is; it is case to case and does not reach these records.

Each of the seven SHALL carry the authorization block
`{"scope": "private", "read": ["authenticated"], "create": ["authenticated"], "update": ["authenticated"], "delete": ["dossiq-coordinators"]}`,
and the register version SHALL be raised so it reaches an installed instance. No schema of the
seven SHALL grant read to authenticated users without `scope` `private`.

`OCA\Dossiq\Woo\WooAssessmentAccess::reconcile(string $caseId): array` SHALL also make the grants
on every record of the seven that names the case equal this table, with owning group
`dossiq-coordinators` on each, and SHALL revoke every user grant it did not just compute:

| record | case assignee | reviewer named by a `wooReviewBatch` of the case |
| --- | --- | --- |
| `wooDocumentReview` | read, update | read, update, only on the reviews of their own batch |
| `wooReviewBatch` | read, update | read, only on their own batch |
| `wooTriageRule` | read, update | none |
| `wooExclusion` | read, update | none |
| `wooSearchPlan` | read, update | none |
| `wooCollectionQuery` | read, update | none |
| `wooDeliveredSet` | read | none |

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
exclusion route, the rule create, the batch create and `WooPublicationService::publish()`. The
repair step of REQ-WRT-001 runs without a user and SHALL NOT call it; the records it creates wait
for the administrator route of REQ-WSA-006, which SHALL reconcile the seven as well.

Every dossiq route on these records SHALL also check access through `CaseAccessGuard` for the case.

#### Scenario: A reviewer scoped to one request sees only that request
- **GIVEN** Woo cases X and Y in the same organisation, reviewer A named by a batch of X only, and a coordinator who created that batch
- **WHEN** A lists `wooDocumentReview` objects through dossiq's routes and through the OpenRegister API
- **THEN** both SHALL answer the reviews of A's batch in X and none of Y's

#### Scenario: No grant, no read
- **GIVEN** an authenticated user who is not the assignee of any Woo case, is named by no batch and is not a coordinator
- **WHEN** they read Woo case X's `wooTriageRule`, `wooSearchPlan` and `wooDeliveredSet` objects through either path
- **THEN** both paths SHALL refuse or list nothing

#### Scenario: No Woo record declares a hierarchy, so every one imports
- **GIVEN** dossiq's register as the importer reads it
- **WHEN** an instance imports it
- **THEN** none of the eight Woo schemas SHALL carry `x-openregister-hierarchy`, each of the seven SHALL read back with `authorization.scope` `private`, and the import SHALL report no failed schema

#### Scenario: A grant the caller may not set is reported, not retried
- **GIVEN** a `wooDocumentReview` of case X owned by user C, and handler H, who is not a coordinator, creating a batch for reviewer R that includes it
- **WHEN** the batch is created
- **THEN** the batch SHALL exist, `reconcile()` SHALL answer `refused` 1, R SHALL NOT read that review, and the case page SHALL show one record waiting for a coordinator
- **AND** when a coordinator then runs the reconcile for X, R SHALL read it

#### Scenario: A change of hands moves the grants on every record
- **GIVEN** Woo case X with a search plan, two triage rules and a batch, and assignee `j.devries`
- **WHEN** a coordinator sets the assignee to `a.smit` (REQ-WSA-005)
- **THEN** `a.smit` SHALL read and update those records and `j.devries` SHALL read none of them
