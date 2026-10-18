# catalogue Delta: catalogue-object-types

**Status**: draft
**Scope**: the Objecttypen card on the catalogue page (board DqCatalogus).

## ADDED Requirements

### Requirement: The catalogue MUST list only the object types in dossiq's own register

The catalogue page SHALL list as object types the schemas that dossiq's own register references, read on the server. It MUST NOT list schemas of other apps' registers or OpenRegister's virtual nc-* schemas. Each row SHALL carry the schema's title (its slug when untitled), description, slug, version and number of properties, and the rows SHALL be sorted by title.

#### Scenario: An administrator opens the catalogue

- **GIVEN** dossiq's register references the schemas Boom and Taak
- **AND** the instance also has the schemas nc-user and another app's Hello Message
- **WHEN** an administrator opens Instellingen, Catalogus
- **THEN** the Objecttypen card lists Boom and Taak
- **AND** it lists neither nc-user nor Hello Message

#### Scenario: No register is configured

- **GIVEN** dossiq has no register configured
- **WHEN** an administrator opens the catalogue
- **THEN** the Objecttypen card says there are no object types yet

### Requirement: The object type list MUST be admin-only

`GET /api/catalogue/object-types` SHALL be reachable only by an administrator, enforced by Nextcloud before the method runs.

#### Scenario: A user who is not an administrator asks for the list

- **WHEN** a user who is not an administrator requests `GET /apps/dossiq/api/catalogue/object-types`
- **THEN** Nextcloud refuses the request
