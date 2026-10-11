## ADDED Requirements

Document review is a generic capability of dossiq core (decision 182): any case type switches it on
with `documentReview.enabled` on the case type, and configures its review depth there. No class,
schema or route of this capability is named after a procedure. How a procedure uses it is
configuration in that procedure's case-type spec; for the Woo-verzoek see REQ-WRT-W01 in the
`woo-review-triage` spec of this change.

Two terms used below:

- **the case's verdict** is the per-document judgement the case type records after review. For a
  Woo-verzoek that is `wooDocumentAssessment.classification`.
- **a closing step** is a step that decides on or publishes the reviewed set. The case type names
  its closing steps; for a Woo-verzoek they are the decision and the publication.

### Requirement: Relevance is marked apart from the verdict, and reported (REQ-WRT-001)

Each document on a case whose case type enables document review SHALL have one `documentReview`
with `case`, `documentRef`, `relevance` (`unmarked`, `in-scope`, `out-of-scope`), `relevanceSource`
(`reviewer` or `rule`), `rule` (when a rule decided), `markedBy`, `markedAt`, `batch`, `depth`,
`pageCount`, `pagesRequired` and `pagesSeen`. Only `in-scope` documents SHALL need a verdict. The
outstanding list of the case SHALL hold in-scope documents without a verdict and unmarked documents,
and SHALL NOT hold out-of-scope ones. GET `/api/cases/{id}/review/summary` SHALL report
`{unmarked, inScope, outOfScope}` beside the verdict counts. POST
`/api/cases/{id}/review/documents/{documentRef}/relevance` with `{relevance}` marks one document,
behind the case mutation guard.

#### Scenario: Out of scope needs no verdict, and both counts are reported
- **GIVEN** a case with document review enabled and 10 documents: 6 marked in scope, 3 out of scope, 1 unmarked, and 4 in-scope ones with a verdict
- **WHEN** the case summary is read
- **THEN** it SHALL report `inScope` 6, `outOfScope` 3, `unmarked` 1 and verdicts for 4
- **AND** the outstanding list SHALL hold the 2 in-scope documents without a verdict and the unmarked one

### Requirement: Rules mark documents without opening them, visibly (REQ-WRT-002)

A case SHALL hold `triageRule` objects with `name`, `conditions` (all of: `custodian`,
`sourceSystem`, `mimeType`, `dateFrom`, `dateTo`, `senderDomain`, `textContains`,
`textNotContains`), `effect` (`in-scope` or `out-of-scope`), `order` and `active`. POST
`/api/cases/{id}/review/triage/apply` SHALL evaluate the active rules in order on every `unmarked`
document, mark the first match with its effect, set `relevanceSource` `rule` and `rule`, and answer
the count per rule. A rule SHALL NOT change a document a reviewer marked. A reviewer MAY change a
rule's marking; the review SHALL then hold `relevanceSource` `reviewer` and `overturnedRule`. The case
page SHALL list the rules with their conditions and the number of documents each decided. The
conditions `custodian` and `sourceSystem` read the document's provenance on the case document link;
until those fields exist a rule that names them SHALL be refused, not silently ignored.

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

POST `/api/cases/{id}/review/batches` SHALL create a `reviewBatch` with `name`, `documents` (a list,
or a filter), and `assignee` (a Nextcloud user). The filter takes `rule`; `custodian` and
`sourceSystem` SHALL be refused with `review-batch-filter-unknown` until the case document link
carries them. It SHALL write the batch on each listed review, and SHALL create a dossiq task on the
case assigned to that reviewer, naming the batch. A document SHALL be in at most one open batch; a
recall sample batch (REQ-WRS-002) does not count. The batch, its assignee and its progress (with a
verdict, of total) SHALL be visible on the case before any verdict is made, through GET
`/api/cases/{id}/review/batches`.

#### Scenario: Two batches for two reviewers
- **GIVEN** 40 in-scope documents
- **WHEN** the handler creates batch "Mail 2025" with 25 documents for reviewer A and batch "Notities" with 15 for reviewer B
- **THEN** A and B SHALL each have a task naming their batch, and the case SHALL show both batches at 0 of 25 and 0 of 15

#### Scenario: A document cannot be in two open batches
- **GIVEN** a document in batch "Mail 2025"
- **WHEN** the handler adds it to another batch
- **THEN** the add SHALL be refused, naming the batch it is in

### Requirement: Review depth is set per document type on the case type, and recorded (REQ-WRT-004)

The case type's `documentReview.reviewDepth` SHALL map a document type (the informatieobjecttype,
or a mime class when there is none) to `{mode: 'every-page'|'sample', sampleSize}`, with
`every-page` as the default for any type it does not name. When a batch is created, each review
SHALL get `depth` (the mode and size that applied) and `pagesRequired`: every page for
`every-page`, or the sampled page numbers, drawn once with a recorded seed, for `sample`. Because
the depth is case-type configuration, it is versioned and copied with the case type.

#### Scenario: Sampling large spreadsheet exports
- **GIVEN** a case type whose review depth is `sample` with size 5 for type "export", and a 200-page export in a new batch
- **WHEN** the batch is created
- **THEN** that review SHALL record depth `sample` 5 and five `pagesRequired` with the seed
- **AND** a 3-page letter in the same batch SHALL record `every-page` and pages 1 to 3

### Requirement: Nothing is decided or published before the required pages are seen (REQ-WRT-005)

The review viewer SHALL report the pages a reviewer displayed to POST
`/api/cases/{id}/review/documents/{documentRef}/pages-seen` with `{pages: [...], pageCount}`, which
SHALL add them to `pagesSeen` with the reviewer and time, behind the case read guard (seeing is
reading). Each page SHALL be reported once per viewing. A verdict on an in-scope document SHALL be
refused, through the case type's bulk route and through a direct OpenRegister write, while any of
its `pagesRequired` is not in `pagesSeen`. Every closing step of the case type SHALL refuse with 409
while any in-scope document has unseen required pages, naming the documents and pages.

#### Scenario: A verdict set without opening is refused
- **GIVEN** an in-scope 3-page document with no pages seen
- **WHEN** a handler writes its verdict through the OpenRegister API
- **THEN** the write SHALL be refused, naming the unseen pages 1 to 3

#### Scenario: The closing step waits for the last page
- **GIVEN** every in-scope document with a verdict, but page 7 of one document unseen
- **WHEN** the handler runs a closing step
- **THEN** it SHALL answer 409 naming that document and page 7

#### Scenario: The viewer reports each displayed page once
- **GIVEN** a reviewer who opens a 3-page document in the review viewer and pages through it twice
- **WHEN** the pages are displayed
- **THEN** the viewer SHALL report pages 1, 2 and 3 once each, with the page count

### Requirement: A stopping rule is declared before review and then fixed (REQ-WRS-001)

A case SHALL hold at most one `stoppingRule` with `targetRecall` (between 0.5 and 0.99),
`confidence` (0.90, 0.95 or 0.99), `declaredBy` and `declaredAt`, declared through POST
`/api/cases/{id}/review/stopping-rule` behind the case mutation guard. It SHALL be declarable only
while no `documentReview` of the case has `relevance` other than `unmarked`. Once any document is
marked, the route and every direct update or delete of the rule SHALL be refused with the sentence
that a stopping rule is declared before review. A case without a declared rule SHALL show that no
stopping rule was declared, and SHALL never record one as met.

#### Scenario: Declared in advance
- **GIVEN** a case with no document marked yet
- **WHEN** the handler declares target recall 0.80 at confidence 0.95
- **THEN** the rule SHALL be stored with the handler and the time

#### Scenario: Not changeable once review started
- **GIVEN** a declared rule and one document marked in scope
- **WHEN** the handler tries to lower the target to 0.70
- **THEN** the change SHALL be refused and the rule SHALL stay 0.80

### Requirement: The recall is estimated from an elusion sample, with its uncertainty (REQ-WRS-002)

POST `/api/cases/{id}/review/recall/samples` with `{size, assignee}` SHALL draw a simple random
sample of that size from the case's null set (documents `out-of-scope` or `unmarked`) with a stored
seed, SHALL store it as a `recallSample` with `seed`, `drawnAt`, `nullSetSize` (N), `found` at the
time of drawing, and the sampled documents, and SHALL create a sample batch for review. POST
`/api/cases/{id}/review/recall/samples/{sampleId}/judgements` with `{documentRef, inScope}` SHALL
judge one sampled document in or out of scope in the sample, without changing its marking in the
review. When every sampled document is judged, dossiq SHALL compute and store `k`, `elusionRate`,
`elusionUpper` (the one-sided Clopper-Pearson bound at the rule's confidence, or 0.95 without a
rule), `recallEstimate` and `recallLowerBound` as the method in the `woo-review-recall-and-stopping`
proposal defines them, rounded to four decimals. GET `/api/cases/{id}/review/recall` SHALL answer
the rule and every sample, each estimate with n, k, N, found, the confidence and the date. A sample
with judgements outstanding SHALL show its progress and no estimate.

#### Scenario: Few relevant documents in the null set
- **GIVEN** found 400 and a null set of 1600, and a sample of 200 with 2 judged in scope
- **WHEN** the last sampled document is judged
- **THEN** `recallEstimate` SHALL be 0.9615 and `recallLowerBound` at 0.95 SHALL be 0.8892

#### Scenario: More relevant documents in the null set
- **GIVEN** the same counts and 10 judged in scope
- **WHEN** the estimate is computed
- **THEN** `recallEstimate` SHALL be 0.8333 and `recallLowerBound` SHALL be 0.7500

#### Scenario: No estimate before the sample is judged
- **GIVEN** a sample of 200 with 150 judged
- **WHEN** the case is read
- **THEN** it SHALL show the sample as in progress, 150 of 200, and no estimate

### Requirement: Meeting the stopping rule is recorded (REQ-WRS-003)

When an estimate's `recallLowerBound` at the declared confidence is at least `targetRecall`, dossiq
SHALL write one entry with action `dossiq.review.stopping-rule-met` on OpenRegister's audit trail of
the case, with the rule, the sample, the estimate, the lower bound and the time, SHALL stamp the
sample's `stoppingRuleMetAt`, and SHALL add an internal timeline entry of kind `stopregel-gehaald`
saying the stopping rule was met with those numbers. An estimate below the target SHALL record
nothing and the case SHALL show the gap. A rule SHALL be recorded as met at most once per sample.

#### Scenario: Met
- **GIVEN** a rule of 0.80 at 0.95 and the first scenario's sample
- **WHEN** the estimate is computed
- **THEN** a `dossiq.review.stopping-rule-met` entry SHALL exist with lower bound 0.8892 and target 0.80, and the timeline SHALL say the stopping rule was met

#### Scenario: Not met
- **GIVEN** the same rule and the second scenario's sample
- **WHEN** the estimate is computed
- **THEN** no `dossiq.review.stopping-rule-met` entry SHALL exist and the case SHALL show 0.7500 against 0.80
