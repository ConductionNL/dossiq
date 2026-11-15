# Tasks: a-verdict-marks-its-document-in-scope

- [x] 1.1 `DocumentRelevance::markInScopeWhereUnmarked()`: mark the unmarked documents among a set in scope, once per case read
  - Files: lib/Review/DocumentRelevance.php
- [x] 1.2 The assessment save marks the documents whose verdict it saved
  - Files: lib/Service/WOODocumentAssessmentService.php
- [x] 1.3 Tests: a verdict clears the document and only that one, an out-of-scope marking is kept, a refused verdict marks nothing
  - Test: tests/Unit/Service/WOODocumentAssessmentServiceTest.php
- [ ] 1.4 Live: assess the document of a Woo request on the rehearsal instance and move the case to the next stage
