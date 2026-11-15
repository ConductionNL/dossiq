# document-review Delta: a-verdict-marks-its-document-in-scope

**Status**: draft
**Scope**: saving a verdict on a document of a case whose case type enables document review.

## ADDED Requirements

### Requirement: A verdict on an unmarked document marks it in scope (REQ-WRT-006)

When a verdict on a document is saved and that document's relevance is `unmarked`, its `documentReview` SHALL be set to `in-scope` with `relevanceSource` `reviewer`, `markedBy` the person who gave the verdict and `markedAt` the moment of saving. A document already marked `in-scope` or `out-of-scope` SHALL keep its marking. A verdict that is refused SHALL mark nothing.

#### Scenario: Assessing the only document opens the next step
@e2e exclude mutates a shared instance: assessing a document rewrites the case the other suites read; covered by WOODocumentAssessmentServiceTest through the real save path

- **GIVEN** a case with document review enabled and two unmarked documents without a verdict
- **WHEN** a handler saves the verdict `openbaar` on the first
- **THEN** the first document is marked `in-scope` by that handler
- **AND** the outstanding list holds only the second document

#### Scenario: An out-of-scope document stays out of scope
@e2e exclude mutates a shared instance; covered by WOODocumentAssessmentServiceTest

- **GIVEN** a document a reviewer marked `out-of-scope`
- **WHEN** a verdict is saved on it
- **THEN** its relevance is still `out-of-scope`, marked by that reviewer
