# zgw-business-rules-compliance

## ADDED Requirements

### Requirement: A rule that cannot run its check refuses the request (REQ-ZBR-020)

A ZGW business rule that reads the store to decide SHALL tell "the lookup ran and found nothing" from "the lookup could not run". The first is an answer and the rule decides on it. The second is not, and the rule SHALL refuse the request.

The refusal SHALL be HTTP 503 with a `detail` that names the rule and says the check could not be completed, and the `code` `check-not-completed`. It SHALL carry no `invalidParams`: nothing in the request is wrong. Nothing SHALL be stored or deleted.

Every such refusal SHALL be logged at error with the rule and the object it was checking.

This holds for drc-003 (a relation is new), drc-004 (the ZaakInformatieObject or BesluitInformatieObject exists), drc-007 (no ObjectInformatieObject points at the document) and the relation check before a document delete (drc-008a).

A rule SHALL NOT answer a finding it did not make. A duplicate search that failed is not a duplicate.

#### Scenario: The counterpart lookup of drc-004 fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by DrcObjectLinkRulesTest "a found counterpart passes and a failed lookup refuses" and ZgwDrcRulesServiceTest. -->
- **GIVEN** an objectinformatieobject for a case and a document
- **WHEN** the search for the ZaakInformatieObject throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the detail names drc-004
- **AND** the log holds the rule, the case and the document

#### Scenario: The counterpart lookup of drc-004 finds nothing
<!-- @e2e exclude Unchanged behaviour, proven by DrcObjectLinkRulesTest "a missing counterpart is inconsistent". -->
- **GIVEN** the same objectinformatieobject
- **WHEN** the search runs and finds no ZaakInformatieObject
- **THEN** the create is refused with 400 and code `inconsistent-relation`

#### Scenario: The relation search of a document delete fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwDrcRulesServiceTest and DrcEioLifecycleTest "a document whose relations cannot be read is not deleted". -->
- **GIVEN** a document
- **WHEN** a search for the relations that point at it throws
- **THEN** the delete is refused with 503 and code `check-not-completed`
- **AND** the document, its gebruiksrechten and its files stay

#### Scenario: The duplicate search of drc-003 fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by DrcDocumentRelationsTest "a failed duplicate search refuses as not verified". -->
- **GIVEN** an objectinformatieobject nobody stored before
- **WHEN** the duplicate search throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the answer does not say the combination exists

### Requirement: A case type is not deleted when its cases cannot be read (REQ-ZBR-021)

The delete check of a case type SHALL block the delete when it cannot read the cases that use the type, or the final statuses that say which of them are closed. The ZGW delete SHALL answer 503 with the code `destroy_check_not_completed` and a detail that says the check could not be completed. A confirmation flag SHALL NOT override it.

A check that ran and found no cases SHALL let the delete through, as before. Active cases stay a 409 `destroy_blocked_active_cases`, and closed cases stay a 409 `destroy_requires_confirmation` until confirmed.

The refusal SHALL be logged at error with the check and the case type.

#### Scenario: The cases cannot be read
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZtcPublishAndDeleteChecksTest "a check that cannot run blocks the delete" and ZgwBusinessRulesServiceDestroyGuardTest. -->
- **GIVEN** a case type
- **WHEN** the search for its cases throws
- **THEN** the delete is refused with 503 and code `destroy_check_not_completed`
- **AND** the case type stays

#### Scenario: Nobody uses the case type
<!-- @e2e exclude Unchanged behaviour, proven by ZtcPublishAndDeleteChecksTest "an unused case type may be deleted". -->
- **GIVEN** a case type
- **WHEN** the search runs and finds no cases
- **THEN** the delete goes through
