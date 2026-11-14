## ADDED Requirements

### Requirement: A document lookup answers only its own schema

Looking up a case, a document record or a document type by uuid SHALL answer only a row that lives in the configured schema for it. A row that OpenRegister found in another schema of the register SHALL count as not found.

#### Scenario: A file attached to a document's own folder
- GIVEN the example set writes the document "Advies Groenbeheer" with sender Groenbeheer
- WHEN it attaches the file to the document's own folder
- THEN the document's uuid is not taken for a case
- AND the file has exactly one document record, the one the set wrote

### Requirement: Existing second records of a file are removed

An upgrade SHALL keep one document record per file id: the first one written. Every later record of that file SHALL be deleted with its joins, once. A later record with a folder of its own SHALL stay and be named in the log.

#### Scenario: A set document with a twin
- GIVEN "Advies Groenbeheer" has two records for one file, the second without a sender
- WHEN the repair runs
- THEN the second record and its joins are gone
- AND the case lists the document with sender Groenbeheer
