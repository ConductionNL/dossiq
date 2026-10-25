## ADDED Requirements

### Requirement: A review case hides Scan and Visible to by default

The Files tab on a case whose case type sets `documentReview.enabled` SHALL
start with the Scan result and Visible to columns hidden, and SHALL keep both
in the Columns menu. On any other case both columns SHALL show by default.

#### Scenario: a review case
- GIVEN a case whose case type reviews its documents
- WHEN the Files tab opens for a user who never chose columns
- THEN the table shows the name column without Scan result and Visible to
- AND Columns lists both, unticked

#### Scenario: a plain case
- GIVEN a case whose case type does not review its documents
- WHEN the Files tab opens
- THEN the table shows Document, Scan result and Visible to
