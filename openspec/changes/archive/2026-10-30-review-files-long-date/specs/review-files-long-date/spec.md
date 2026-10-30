## ADDED Requirements

### Requirement: A review case dates its files in full

On a case that reviews its documents the second line under a file SHALL end with the long date including the year.

#### Scenario: a PDF on a Woo case
- GIVEN a PDF modified 14 January 2026 on a review case
- THEN its second line reads "PDF · <size> · 14 januari 2026"
