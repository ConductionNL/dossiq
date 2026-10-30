# Tasks: partly-public-documents-get-a-redacted-copy

## 1. Backend

- [x] 1.1 `FilinqRedactionClient`: `detect()`, `markChecked()`, `anonymize()`; a review refusal is the outcome `not_checked`
  - Spec ref: specs/document-redaction/spec.md, REQ-DRD-001, REQ-DRD-002
  - Files: lib/Service/FilinqRedactionClient.php
  - Test: tests/Unit/Service/FilinqRedactionClientTest.php
- [x] 1.2 `DocumentRedactionService` with `RedactionEntities` and `RedactedCopyRecorder`; `WOODocumentAssessmentService::saveRedaction()` and `partlyPublicAssessments()`
  - Spec ref: REQ-DRD-001 to REQ-DRD-004
  - Files: lib/Service/Redaction/, lib/Service/WOODocumentAssessmentService.php, lib/Settings/register.d/83-woo-document-assessment.json (`redaction`, schema 1.3.0)
  - Test: tests/Unit/Service/Redaction/DocumentRedactionServiceTest.php through filinq's own review gate
- [x] 1.3 Routes and `DocumentRedactionController`, case handler only
  - Files: appinfo/routes.php, lib/Controller/DocumentRedactionController.php
  - Test: tests/Unit/Controller/DocumentRedactionControllerTest.php
- [x] 1.4 The redacted copy is not a document under review
  - Spec ref: REQ-DRD-003
  - Files: lib/Review/OutstandingDocuments.php
  - Test: tests/Unit/Review/OutstandingDocumentsTest.php
- [x] 1.5 Remove `WOORedactionService`; approving a Hermiq proposal writes no copy
  - Spec ref: specs/woo-llm-anonymisation/spec.md
  - Files: lib/Service/WOORedactionService.php (removed), lib/Service/WOOAnonymisationAssistService.php
  - Test: tests/Unit/Service/WOOAnonymisationAssistServiceTest.php

## 2. Frontend

- [x] 2.1 The redaction dialog per board DqLakken, opened from the case header, with the upload action
  - Files: src/dialogs/DocumentRedactionDialog.vue, src/registry.js, src/manifest.json (`document-redact`), l10n
  - Test: tests/vitest/documentRedactionDialog.spec.js
  - Spec ref: REQ-DRD-005
