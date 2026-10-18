# catalogue Delta: catalogue-object-types

**Status**: draft
**Scope**: the Objecttypen card on the catalogue page (board DqCatalogus).

## ADDED Requirements

### Requirement: The catalogue MUST list the object types cases link

The catalogue page SHALL list as object types the distinct `objectType` values of the case-object links (`caseObject`) in dossiq's register, read on the server, each with the number of distinct cases that link an object of that type. Rows SHALL be ordered by that number, most first, then by name. Schemas MUST NOT be listed as object types.

#### Scenario: Cases link objects

- **GIVEN** case 1 links two buildings, case 2 links one building and case 3 links a vehicle
- **WHEN** an administrator opens Instellingen, Catalogus
- **THEN** the Objecttypen card lists building, used in 2 zaken, then vehicle, used in 1 zaak
- **AND** it lists no schema such as case, task or nc-user

#### Scenario: No case links an object

- **GIVEN** no case links an object
- **WHEN** an administrator opens the catalogue
- **THEN** the Objecttypen card says object types appear here once cases link objects

### Requirement: The object type list MUST be admin-only and MUST NOT hide a failed read

`GET /api/catalogue/object-types` SHALL be reachable only by an administrator, enforced by Nextcloud before the method runs. When OpenRegister cannot answer, the endpoint SHALL answer 503 rather than an empty list.

#### Scenario: A user who is not an administrator asks for the list

- **WHEN** a user who is not an administrator requests `GET /apps/dossiq/api/catalogue/object-types`
- **THEN** Nextcloud refuses the request

#### Scenario: OpenRegister cannot answer

- **GIVEN** OpenRegister is not available
- **WHEN** an administrator requests the list
- **THEN** the answer is 503
