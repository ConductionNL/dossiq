## ADDED Requirements

### Requirement: A document names its file before the file moves into the case

When a join moves a document's file from the document's own folder into its case's folder, the document record SHALL name that file before the move, so the move's file event refreshes that record and SHALL NOT project a second record of the file.

#### Scenario: An example set document joined to its case
- GIVEN the example set wrote "Offerte herplant essen" and attached its file to the document's own folder
- WHEN the set's join moves the file into the case folder
- THEN the file has exactly one document record, the one the set wrote
- AND that record carries the file's size, format and SHA-256 checksum
