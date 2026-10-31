## ADDED Requirements

### Requirement: documents are copied to a case the caller may add to

The copy endpoint MUST refuse, before reading anything, a target case the caller may not add documents to. It MUST copy each document the caller may read as a new document on the target case with provenance `{ kind: "copy", document, by, at }`, MUST NOT copy a document the caller may not read, and MUST answer one result per document.

#### Scenario: two documents to another case
- GIVEN two ticked documents on 2026-0082 and the handler may add documents to 2026-0090
- WHEN they copy them to 2026-0090
- THEN 2026-0090 holds two new documents that say they are copies of the originals
