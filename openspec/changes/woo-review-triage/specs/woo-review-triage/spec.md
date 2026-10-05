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

`wooDocumentAssessment`, `wooDocumentReview`, `wooReviewBatch`, `wooTriageRule`, `wooExclusion`,
`wooSearchPlan`, `wooCollectionQuery` and `wooDeliveredSet` SHALL declare their case reference as
their `x-openregister-hierarchy` parent with read and update inherited, in the form the case schema
uses for `parentCase`, and SHALL be listed in `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS` handling so the
key reaches the instance. Their schema authorisation SHALL NOT grant read to all authenticated
users. Every dossiq route on these records SHALL check access through `CaseAccessGuard` for the
case. Until OpenRegister enforces the hierarchy, these schemas SHALL be readable through the
OpenRegister API by administrators only, so the safe state is no access rather than all access.

#### Scenario: A reviewer scoped to one request sees only that request
- **GIVEN** reviewer A granted on Woo case X only, and Woo case Y in the same organisation
- **WHEN** A lists `wooDocumentAssessment` objects through dossiq's routes
- **THEN** A SHALL see X's assessments and none of Y's
- **AND** through the OpenRegister API A SHALL see X's and none of Y's once OpenRegister enforces the hierarchy, and none at all before that

#### Scenario: No grant, no read
- **GIVEN** an authenticated user with no grant on any Woo case
- **WHEN** they read Woo case X's reviews through either path
- **THEN** both SHALL refuse
