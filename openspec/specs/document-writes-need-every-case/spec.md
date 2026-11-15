# document-writes-need-every-case Specification

## Purpose
What dossiq does for document writes need every case. The requirements were first written by the change `document-writes-need-every-case` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-document-writes-need-every-case/`) and cover: a document write needs the change right on every case of the document; a bulk document write is refused whole.

## Requirements

### Requirement: a document write needs the change right on every case of the document

A write to a document's metadata or status MUST be refused, before the document is read or anything is written, when the caller lacks the change right on any case the document belongs to; the refusal MUST name the document and that case. A document that belongs to no case keeps the read clearance as its rule.

#### Scenario: a document shared with a case the caller may not change
- GIVEN a document on case A (the caller may change it) and case B (they may not)
- WHEN the caller changes its title
- THEN the request is refused naming case B, and the document is unchanged

### Requirement: a bulk document write is refused whole

A bulk status or metadata write MUST be refused as a whole when the caller may not write one of the documents, naming that document, and nothing in the batch is written.

#### Scenario: one locked document in a batch
- GIVEN three ticked documents, one on a case the caller may not change
- WHEN they mark the three as final
- THEN nothing is marked and the answer names the locked document
