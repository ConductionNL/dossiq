# woo-llm-anonymisation Delta: partly-public-documents-get-a-redacted-copy

**Status**: draft
**Scope**: what approving a Hermiq redaction proposal does.

## MODIFIED Requirements

### Requirement: Redaction proposals require explicit human review before any hand-off

The system MUST NOT treat a redaction proposal's spans as approved without an explicit, authenticated reviewer decision of `approve` on a proposal currently in `pending_review` status. Approving or rejecting a proposal MUST NOT write a redacted copy: the copy is written by the redaction step after an officer checked the passages filinq acts on (document-redaction REQ-DRD-002), which takes Hermiq's spans as passages of its own.

@e2e exclude Hermiq is not installed in the dossiq e2e environment. Covered by WOOAnonymisationAssistServiceTest.

#### Scenario: A proposal is generated but never reviewed

- **GIVEN** a `pending_review` redaction proposal exists for a document
- **WHEN** no review decision has been recorded
- **THEN** its spans MUST NOT be treated as approved
- **AND** no redacted copy MUST be written for that document as a result of this feature

#### Scenario: A reviewer approves a proposal

- **GIVEN** a `pending_review` redaction proposal
- **WHEN** an authorized reviewer calls the review endpoint with `decision: 'approve'`
- **THEN** the system MUST mark the proposal `approved` and record the approved spans, the reviewer and the timestamp
- **AND** no redacted copy MUST be written

#### Scenario: A reviewer rejects a proposal

- **GIVEN** a `pending_review` redaction proposal
- **WHEN** an authorized reviewer calls the review endpoint with `decision: 'reject'`
- **THEN** the system MUST mark the proposal `rejected` with no approved spans

#### Scenario: A decision is attempted on a document with no pending proposal

- **GIVEN** a document with no `pending_review` redaction proposal (never requested, or already reviewed)
- **WHEN** a review decision is submitted
- **THEN** the system MUST reject the request and MUST NOT change the document's assessment
