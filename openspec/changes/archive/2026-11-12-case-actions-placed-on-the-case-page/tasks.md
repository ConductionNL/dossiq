# Tasks: case-actions-placed-on-the-case-page

- [x] **T01**: `onCase` and `successMessage` on `createBezwaar` (window 42 days from `decisionSentOn`, with `closedText`) and on `createKlacht` in `lib/Portal/CitizenManifest.php`
- [x] **T02**: The case page in `lib/Portal/PortalPages.php` drops the `cta` blocks for both; the overview declares neither
- [x] **T03**: `decisionSentOn` on the case (`lib/Settings/register.d/99-case-decision-sent-on.json`), written by `CaseRemedy::recordSent()` when a decision is sent
- [x] **T04**: Unit tests for the contribution shape (`tests/Unit/Portal/PortalCasePageTest.php`) and for the stamp (`tests/Unit/Service/RemedyTermBindingTest.php`)
