## ADDED Requirements

### Requirement: REQ-ZAK-013 A document row shows its scan verdict

The Files tab SHALL declare a Scan column showing the verdict
`files_antivirus` recorded for the node (clean with time, infected, not
scanned) and the properties dialog SHALL show it beside the hash. An
absent scanner SHALL read as not scanned.

#### Scenario: A scanned file reads clean
@e2e exclude the scanner is a platform app not driven in e2e; covered by a unit test over a stubbed verdict reader

- **GIVEN** a file `files_antivirus` marked clean at 09:00
- **WHEN** the row is rendered
- **THEN** Scan SHALL read clean, 09:00

#### Scenario: No scanner, no claim
@e2e tests/e2e/scan-verdict.spec.ts

- **GIVEN** `files_antivirus` is not installed
- **WHEN** you open a document's properties
- **THEN** Scan SHALL read not scanned
