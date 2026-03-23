## ADDED Requirements

### Requirement: Eindstatus detection via volgnummer fallback (zrc-007a)
When creating a zaak status, the system SHALL detect whether it is the eindstatus even when `isEindstatus` is not explicitly set, by treating the statustype with the highest `volgnummer` as the eindstatus. Upon eindstatus creation the system SHALL set `zaak.einddatum` to the current date.

#### Scenario: Eindstatus by highest volgnummer
- **WHEN** a status is created with a statustype whose `volgnummer` equals the maximum among all statustypes for the zaaktype
- **AND** `isEindstatus` is absent or null on that statustype
- **THEN** the system SHALL set `zaak.einddatum` to the current ISO 8601 date
- **AND** the zaak object SHALL be persisted with the updated `einddatum`

#### Scenario: Non-eindstatus does not set einddatum
- **WHEN** a status is created with a statustype whose `volgnummer` is NOT the maximum
- **THEN** the system SHALL NOT modify `zaak.einddatum`

### Requirement: Validate indicatieGebruiksrecht before eindstatus (zrc-007q)
The system SHALL reject eindstatus creation if any linked ZaakInformatieObject has `indicatieGebruiksrecht === null`.

#### Scenario: Block eindstatus when indicatieGebruiksrecht is unset
- **WHEN** eindstatus creation is requested
- **AND** one or more ZaakInformatieObjecten linked to the zaak have `indicatieGebruiksrecht === null`
- **THEN** the system SHALL return HTTP 400
- **AND** the response body SHALL contain a validation error referencing `indicatieGebruiksrecht`

#### Scenario: Allow eindstatus when all indicatieGebruiksrecht are set
- **WHEN** eindstatus creation is requested
- **AND** all linked ZaakInformatieObjecten have `indicatieGebruiksrecht` set to `true` or `false`
- **THEN** the system SHALL proceed with eindstatus creation

### Requirement: Set indicatieGebruiksrecht on zaak close (zrc-007b)
After a zaak is closed (eindstatus confirmed), the system SHALL update all linked informatieobjecten to set `indicatieGebruiksrecht = true` if not already set.

#### Scenario: Cascade set on eindstatus
- **WHEN** eindstatus is successfully created and `zaak.einddatum` is set
- **THEN** the system SHALL query all ZaakInformatieObjecten for the zaak
- **AND** for each linked informatieobject where `indicatieGebruiksrecht` is `false` or `null`
- **THEN** the system SHALL update the informatieobject to `indicatieGebruiksrecht = true` via ObjectService

### Requirement: Enforce zaken.heropenen scope for reopening (zrc-008c)
The system SHALL check that the consumer has the `zaken.heropenen` authorization scope before allowing a closed zaak to be reopened.

#### Scenario: Reopen rejected without scope
- **WHEN** a PATCH request attempts to remove `einddatum` (reopen) from a closed zaak
- **AND** the consumer's authorization context does NOT include `zaken.heropenen`
- **THEN** the system SHALL return HTTP 403 Forbidden

#### Scenario: Reopen allowed with scope
- **WHEN** a PATCH request attempts to reopen a closed zaak
- **AND** the consumer holds the `zaken.heropenen` scope
- **THEN** the system SHALL allow the update

### Requirement: Correct error codes for communicatiekanaal (zrc-010)
The system SHALL return error code `invalid-resource` (not `bad-url`) when `communicatiekanaal` contains a URL that does not resolve to a valid resource.

#### Scenario: Invalid communicatiekanaal URL
- **WHEN** a zaak is created with a `communicatiekanaal` value that is not a valid resource URL
- **THEN** the system SHALL return HTTP 400
- **AND** the error object SHALL contain `code: "invalid-resource"`

### Requirement: Correct error code for hoofdzaak not found (zrc-013a)
The system SHALL return error code `does-not-exist` (not `no_match`) when the referenced `hoofdzaak` cannot be found.

#### Scenario: Hoofdzaak not found
- **WHEN** a zaak is created with a `hoofdzaak` URL that does not resolve to an existing zaak
- **THEN** the system SHALL return HTTP 400
- **AND** the error object SHALL contain `code: "does-not-exist"`

### Requirement: Validate productenOfDiensten as subset of zaaktype (zrc-015)
The system SHALL validate that all values in `productenOfDiensten` on a zaak are a subset of the zaaktype's `productenOfDiensten` list.

#### Scenario: Product not in zaaktype rejected
- **WHEN** a zaak is created or updated with a `productenOfDiensten` entry that is not in the zaaktype's allowed list
- **THEN** the system SHALL return HTTP 400 with a validation error identifying the invalid product

#### Scenario: Valid productenOfDiensten accepted
- **WHEN** all `productenOfDiensten` values are present in the zaaktype's allowed list
- **THEN** the system SHALL accept the zaak

### Requirement: Cross-validate sub-resource types belong to zaaktype (zrc-016/018/019/020)
The system SHALL validate that statustypes, resultaattypen, eigenschappen, and roltypen referenced on a zaak belong to the zaak's zaaktype.

#### Scenario: Statustype from wrong zaaktype rejected
- **WHEN** a status is created referencing a statustype that belongs to a different zaaktype
- **THEN** the system SHALL return HTTP 400

#### Scenario: Resultaattype from wrong zaaktype rejected
- **WHEN** a resultaat is created referencing a resultaattype from a different zaaktype
- **THEN** the system SHALL return HTTP 400

#### Scenario: Eigenschap from wrong zaaktype rejected
- **WHEN** a zaakeigenschap is created referencing an eigenschap from a different zaaktype
- **THEN** the system SHALL return HTTP 400

#### Scenario: Roltype from wrong zaaktype rejected
- **WHEN** a rol is created referencing a roltype from a different zaaktype
- **THEN** the system SHALL return HTTP 400

### Requirement: Derive archiefactiedatum from resultaattype (zrc-021)
When a resultaat is set on a zaak, the system SHALL derive `zaak.archiefactiedatum` from the resultaattype's `brondatumArchiefprocedure` configuration.

#### Scenario: Archiefactiedatum derived on resultaat creation
- **WHEN** a resultaat is created with a resultaattype that defines `brondatumArchiefprocedure`
- **THEN** the system SHALL compute `archiefactiedatum` from the procedure and set it on the zaak

#### Scenario: No archiefactiedatum when resultaattype lacks procedure
- **WHEN** the resultaattype has no `brondatumArchiefprocedure`
- **THEN** the system SHALL NOT set `zaak.archiefactiedatum`

### Requirement: Enforce identificatie and bronorganisatie uniqueness (zrc-002)
The combination of `identificatie` + `bronorganisatie` SHALL be unique across all zaken.

#### Scenario: Duplicate identificatie + bronorganisatie rejected
- **WHEN** a zaak is created with an `identificatie` and `bronorganisatie` combination that already exists
- **THEN** the system SHALL return HTTP 400 with a validation error on `identificatie`

#### Scenario: Unique combination accepted
- **WHEN** the `identificatie` + `bronorganisatie` combination is not already used
- **THEN** the system SHALL create the zaak

### Requirement: Cascade-delete ObjectInformatieObject on ZIO or zaak deletion (zrc-005b/023h)
When a ZaakInformatieObject (ZIO) is deleted, the system SHALL also delete the corresponding ObjectInformatieObject (OIO) in the DRC register. When a zaak is deleted, all OIOs linked to its informatieobjecten SHALL be deleted.

#### Scenario: OIO deleted on ZIO deletion
- **WHEN** a ZaakInformatieObject is deleted via DELETE /zaakinformatieobjecten/{uuid}
- **THEN** the system SHALL query the DRC register for an OIO with matching `informatieobject` and `object` values
- **AND** delete the found OIO via ObjectService

#### Scenario: OIOs cascade-deleted on zaak deletion
- **WHEN** a zaak is deleted
- **THEN** the system SHALL delete all ZIOs linked to the zaak
- **AND** for each ZIO, delete the corresponding OIO from the DRC register

### Requirement: Derive vertrouwelijkheidaanduiding from zaaktype without template leakage (zrc-009)
The system SHALL always set `zaak.vertrouwelijkheidaanduiding` from the zaaktype's configured value, overriding any value that leaked from the mapping template.

#### Scenario: Zaaktype value overrides template default
- **WHEN** a zaak is created and the zaaktype defines `vertrouwelijkheidaanduiding`
- **THEN** the system SHALL set the zaak's `vertrouwelijkheidaanduiding` to the zaaktype's value
- **AND** any template-level default SHALL be discarded

#### Scenario: Incoming value used when zaaktype has no value
- **WHEN** the zaaktype has no `vertrouwelijkheidaanduiding` field
- **THEN** the system SHALL use the value from the incoming request as fallback

### Requirement: Filter zaken results by consumer authorization (zrc-006)
The system SHALL filter `GET /zaken` results to include only zaken whose zaaktype and vertrouwelijkheidaanduiding are within the consumer's authorized scope.

#### Scenario: Unauthorized zaaktype excluded from listing
- **WHEN** a consumer calls `GET /zaken`
- **AND** the consumer's `authorizations` do not include a given zaaktype
- **THEN** zaken of that zaaktype SHALL NOT appear in the response

#### Scenario: Exceeds maxVertrouwelijkheidaanduiding excluded
- **WHEN** a zaak's `vertrouwelijkheidaanduiding` exceeds the consumer's `maxVertrouwelijkheidaanduiding` for the zaaktype
- **THEN** that zaak SHALL NOT appear in the listing response

#### Scenario: Unfiltered fallback when no authorizations context
- **WHEN** the `ZgwAuthMiddleware` provides no authorization context for the consumer
- **THEN** the system SHALL return all zaken without filtering (existing behaviour)
