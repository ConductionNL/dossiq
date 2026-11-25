# zgw-business-rules-compliance

## ADDED Requirements

### Requirement: The ZGW publish endpoint runs the publish guard (REQ-ZBR-024)

The ZGW Catalogi publish endpoints for zaaktypen, besluittypen and informatieobjecttypen SHALL require the scope `catalogi.schrijven`, like every other ZTC write. A caller without it SHALL get 403 with code `permission_denied`, and nothing SHALL be read or saved.

Publishing a draft zaaktype SHALL run the same CT-02b guard as an update that sets `isDraft` to false, read the ZGW way: when the zaaktype has status types, one of them SHALL be final, by its own flag or, as ZGW derives `isEindstatus`, as the one with the highest volgnummer; and the zaaktype SHALL have a valid-from date. A zaaktype without status types MAY be published over the ZGW API, as the standard and its conformance collections allow; the admin publish still requires one (CT-02c). A zaaktype that fails it SHALL be refused with 422, code `publish_validation_failed`, and a `detail` that lists what is missing. A guard that could not read the store SHALL refuse with 503, code `check-not-completed`. A refused publish SHALL NOT save the type.

#### Scenario: A caller without the write scope publishes
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishControllerContractTest "all three publish routes require the catalogi write scope". -->
- **GIVEN** a ZGW client whose scopes do not include `catalogi.schrijven`
- **WHEN** it publishes a zaaktype, a besluittype or an informatieobjecttype
- **THEN** the answer is 403 with code `permission_denied`
- **AND** the type stays a draft

#### Scenario: A zaaktype without a final status is published
<!-- @e2e exclude ZGW API route, no UI. Proven by ZgwBusinessRulesServicePublishEndpointTest and ZtcPublishControllerContractTest "a zaaktype the publish guard refuses is not saved". -->
- **GIVEN** a draft zaaktype with status types, none flagged final and none with a volgnummer
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 422 with code `publish_validation_failed`
- **AND** the type stays a draft

#### Scenario: A complete zaaktype is published
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishControllerContractTest "a zaaktype the publish guard passes is published". -->
- **GIVEN** a draft zaaktype with status types, one of them final, and a valid-from date
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published

#### Scenario: A ZGW client publishes a zaaktype whose status types carry only volgnummers
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishAndDeleteChecksTest "a volgnummer makes the highest status type final" and by the ZGW business rules Newman collection. -->
- **GIVEN** a draft zaaktype with a valid-from date and status types with volgnummers 1 and 2, none flagged `isEindstatus`
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published

#### Scenario: A ZGW client publishes a zaaktype without status types
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishAndDeleteChecksTest "a zaaktype without status types may be published" and by ztc-009 in the ZGW business rules Newman collection. -->
- **GIVEN** a draft zaaktype with a valid-from date and no status types
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published
