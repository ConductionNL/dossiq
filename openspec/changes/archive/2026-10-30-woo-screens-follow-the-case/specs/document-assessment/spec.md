# document-assessment delta: woo-screens-follow-the-case

## ADDED Requirements

### Requirement: A saved assessment leaves the outstanding list at once (REQ-DAS-SCR-01)

When the server answers that a document's assessment is saved, the assessment dialog MUST stop listing that document as outstanding at once, also when the next read of the outstanding list does not see the save yet. A document the server did not save MUST stay listed. A label in the dialog MUST break between words, never inside one, unless a single word does not fit the line.

#### Scenario: The saved document leaves the list
@e2e exclude {asserted in tests/vitest/documentAssessDialog.spec.js with a list read that still answers the saved document}

- **GIVEN** the dialog lists two documents without an assessment
- **WHEN** the handler saves the first as Openbaar and the server answers it saved
- **THEN** the first MUST no longer be listed and the second MUST still be
