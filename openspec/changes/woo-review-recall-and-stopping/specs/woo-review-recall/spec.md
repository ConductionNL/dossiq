## ADDED Requirements

### Requirement: A stopping rule is declared before review and then fixed (REQ-WRS-001)

A Woo case SHALL hold one `wooStoppingRule` with `targetRecall` (between 0.5 and 0.99),
`confidence` (0.90, 0.95 or 0.99), `declaredBy` and `declaredAt`. It SHALL be declarable only while
no `wooDocumentReview` of the case has `relevance` other than `unmarked`. Once any document is
marked, every update or delete of the rule SHALL be refused with the sentence that a stopping rule is
declared before review. A case without a declared rule SHALL show that no stopping rule was
declared, and SHALL never record one as met.

#### Scenario: Declared in advance
- **GIVEN** a Woo case with no document marked yet
- **WHEN** the handler declares target recall 0.80 at confidence 0.95
- **THEN** the rule SHALL be stored with the handler and the time

#### Scenario: Not changeable once review started
- **GIVEN** a declared rule and one document marked in scope
- **WHEN** the handler tries to lower the target to 0.70
- **THEN** the change SHALL be refused and the rule SHALL stay 0.80

### Requirement: The recall is estimated from an elusion sample, with its uncertainty (REQ-WRS-002)

POST `/api/cases/{id}/woo/recall/samples` with `{size}` SHALL draw a simple random sample of that
size from the case's null set (documents `out-of-scope` or `unmarked`) with a stored seed, SHALL
store it as a `wooRecallSample` with `seed`, `drawnAt`, `nullSetSize` (N), `found` at the time of
drawing, and the sampled documents, and SHALL create a sample batch for review. Each sampled
document SHALL be judged in or out of scope in the sample, without changing its marking in the
review. When every sampled document is judged, dossiq SHALL compute and store `k`, `elusionRate`,
`elusionUpper` (the one-sided Clopper-Pearson bound at the rule's confidence, or 0.95 without a
rule), `recallEstimate` and `recallLowerBound` as the method in the proposal defines them, rounded
to four decimals. The case SHALL show every estimate with n, k, N, found, the confidence and the
date. A sample with judgements outstanding SHALL show no estimate.

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
SHALL write one `stoppingRuleMet` record on the case, on OpenRegister's audit trail, with the rule,
the sample, the estimate, the lower bound and the time, and an internal timeline entry saying the
stopping rule was met with those numbers. An estimate below the target SHALL record nothing and the
case SHALL show the gap. A rule SHALL be recorded as met at most once per sample.

#### Scenario: Met
- **GIVEN** a rule of 0.80 at 0.95 and the first scenario's sample
- **WHEN** the estimate is computed
- **THEN** a `stoppingRuleMet` record SHALL exist with lower bound 0.8892 and target 0.80

#### Scenario: Not met
- **GIVEN** the same rule and the second scenario's sample
- **WHEN** the estimate is computed
- **THEN** no `stoppingRuleMet` record SHALL exist and the case SHALL show 0.7500 against 0.80
