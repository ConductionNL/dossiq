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

### Requirement: A reference or uniqueness read that fails refuses the write (REQ-ZBR-022)

The store reads every ZGW rule shares (`Zgw/ZgwRuleLookups`) SHALL tell "the read ran and found nothing" from "the read could not run". A read that could not run SHALL throw, and the entry point of the rules SHALL refuse the request with the answer of REQ-ZBR-020: HTTP 503, code `check-not-completed`, no `invalidParams`, logged at error with the rule and what it was reading.

This holds for the identification uniqueness check (a duplicate nobody could search for is not a free identification) and for reference resolution (a zaaktype, informatieobjecttype or besluittype that could not be read is not a type that does not exist).

An object the store does not hold, or does not show the caller, SHALL still read as none, as before. A lookup with nothing to read in, because the request has no store or the setting that names its schema is empty, SHALL still be skipped: an instance without a decision register cannot hold a decision either.

#### Scenario: The uniqueness search fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwRuleLookupsTest "a pair is free only when the search ran", ZgwRulesBaseUniquenessTest and ZgwBusinessRulesServiceLookupFailureTest. -->
- **GIVEN** a zaak with an identificatie and a bronorganisatie
- **WHEN** the search for another zaak with that identificatie throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the answer does not say the identificatie is taken

#### Scenario: A referenced type cannot be read
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwRuleLookupsTest "an object by uuid and schema key" and ZgwBusinessRulesServiceLookupFailureTest "a rule whose read failed refuses the write". -->
- **GIVEN** a write that refers to a zaaktype
- **WHEN** reading that zaaktype throws for another reason than "not found" or "not allowed"
- **THEN** the write is refused with 503 and code `check-not-completed`
- **AND** the log names the rule and the zaaktype

#### Scenario: The referenced type does not exist
<!-- @e2e exclude Unchanged behaviour, proven by ZgwRuleLookupsTest "an object by uuid and schema key". -->
- **GIVEN** a write that refers to a zaaktype the store does not hold
- **WHEN** the read answers "not found"
- **THEN** the rule decides as before, on a type that is not there

### Requirement: A ZGW read, update or delete says why it failed (REQ-ZBR-023)

A ZGW show, update or delete of a stored object SHALL sort a failure of the store into three answers, in one place for every resource:

- **not found** (404, `detail` "Not found"): the store says the object is not there, by a DoesNotExistException or by answering no object;
- **not allowed** (403, code `permission_denied`): the store refuses the caller, by OpenRegister's NotAuthorizedException or any failure carrying code 403;
- **not completed** (503, code `store-not-reached`): any other failure. Nothing SHALL be assumed about the object, and the failure SHALL be logged at error with the operation and the object.

A refused permission SHALL NOT answer 503. A store that was down SHALL NOT answer 404.

A delete that happened SHALL answer 204 even when its notification fails afterwards; that failure SHALL be logged at error.

The writes the Besluiten API makes in another register (the zaakbesluit of brc-006, the objectinformatieobject of brc-005a, brc-005b and brc-009) stay best effort, but a failure SHALL be logged at error with the rule, the resource and the objects involved.

#### Scenario: The store is down when a besluit is deleted
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwServiceStoreFailureTest and BrcControllerTest "a missing resource cannot be deleted". -->
- **GIVEN** a stored besluit
- **WHEN** deleting it fails because the store cannot be reached
- **THEN** the delete answers 503 with code `store-not-reached`
- **AND** no notification goes out

#### Scenario: The caller may not delete the object
<!-- @e2e exclude Proven by ZgwServiceStoreFailureTest "a refused delete answers forbidden" and BrcDocumentLinkHandlerTest. -->
- **GIVEN** a stored object the caller may not delete
- **WHEN** OpenRegister refuses the delete
- **THEN** the delete answers 403 with code `permission_denied`

#### Scenario: The object is not there
<!-- @e2e exclude Unchanged behaviour, proven by ZgwServiceStoreFailureTest "a delete answers not found only for an absent object". -->
- **GIVEN** an id the store does not hold
- **WHEN** it is deleted
- **THEN** the delete answers 404 "Not found"
