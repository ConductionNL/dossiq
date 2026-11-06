# case-management delta: lifecycle-dialog-title-clears-the-close-button

## ADDED Requirements

### Requirement: The lifecycle dialog's title stays clear of its close button (REQ-CM-78)

The dialog that lists what a handler can do with a case SHALL be wide enough that
its title does not run under the dialog's close button.

#### Scenario: The title clears the close button
@e2e exclude {a geometry check between two library-drawn elements; the size is asserted in tests/vitest/caseTransitionOutcome.spec.js and the result is checked on the live instance with a screenshot}

- **GIVEN** a case page
- **WHEN** the handler opens Meer and then Levensloop
- **THEN** the dialog title SHALL not overlap the close button
