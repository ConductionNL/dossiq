## ADDED Requirements

### Requirement: The dossier is linked to the case after the case is answered (REQ-PSM-002)

When a Woo request starts from the resident's own dossier, `start()` SHALL check the dossier is the resident's before it writes the case (REQ-WRI-003, unchanged), and SHALL answer the case without waiting for the dossier link: it SHALL queue one `LinkCollectionToCaseJob` with the register, the case, the dossier and the resident. The job SHALL check again that the dossier is the resident's and SHALL then write one case object per dossier item and record the case in the dossier's `sourceOf` once, exactly as REQ-WRI-004 describes. Where no job list is available the link SHALL be written before answering, as before.

#### Scenario: The case is answered before the dossier is linked
<!-- @e2e exclude Timing; pinned by WooRequestIntakeTest::testWithAJobListTheDossierIsLinkedAfterTheCaseIsAnswered. -->

- **GIVEN** a resident's dossier with two publications
- **WHEN** they start a Woo request from it
- **THEN** the answer names the case, no case object exists yet, and one link job is queued
- **AND** after the job runs the case has two case objects and the dossier names the case once
