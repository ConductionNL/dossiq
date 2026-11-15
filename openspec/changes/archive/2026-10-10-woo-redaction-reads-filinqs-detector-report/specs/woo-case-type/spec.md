## ADDED Requirements

### Requirement: A redaction refused for want of a detector says so
When filinq refuses to anonymise a Woo document because no entity detector
is live, dossiq MUST report the outcome `detection_unavailable` with filinq's
reason and the backend it named, MUST NOT report the document as redacted,
and MUST put it on the manual redaction list with the reason
`filinq_has_no_live_detector`. On a completed run dossiq MUST report the
backend filinq names in its detection report, and MUST treat filinq's
`nothing_found` as `no_entities_detected`.

@e2e exclude The refusal is raised inside filinq's anonymisation service when OpenRegister's entity detection is off or unavailable; a dossiq e2e run cannot switch filinq's detector state. Covered by FilinqRedactionClientTest and WOORedactionServiceTest against a verbatim copy of filinq's exception.

#### Scenario: Detection is switched off
- **GIVEN** a Woo case with a document assessed as deels openbaar, and entity detection switched off
- **WHEN** the document is sent for redaction
- **THEN** it MUST land on the manual list with reason `filinq_has_no_live_detector`
- **AND** no anonymised file MUST be reported

#### Scenario: Filinq names the detector that looked
- **GIVEN** filinq anonymised a document with the presidio detector
- **WHEN** the outcome is reported
- **THEN** its detection backend MUST be `presidio`
