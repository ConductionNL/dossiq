## ADDED Requirements

### Requirement: A Data model entry opens the register's schemas (REQ-ADMIN-022)

The manifest SHALL declare a menu entry Data model in `section:
"integrations"` whose href is OpenRegister's schema page for the dossiq
register, visible to admins only.

#### Scenario: An admin reaches the data model
@e2e tests/e2e/data-model-link.spec.ts

- **GIVEN** you are an admin
- **WHEN** you open the Integrations section of the settings modal
- **THEN** Data model SHALL be listed
- **AND** it SHALL open OpenRegister's schema list for the dossiq register

#### Scenario: A handler does not see it
@e2e tests/e2e/data-model-link.spec.ts

- **GIVEN** you are not an admin
- **WHEN** you open the settings modal
- **THEN** Data model SHALL NOT be listed

### Requirement: Manage object types is one click from the objects (REQ-ADMIN-023)

The Objects section of `#CaseDetail` and the `#CaseObjects` index SHALL
carry a Manage object types link to the same page, visible to admins only.

#### Scenario: From the objects index
@e2e tests/e2e/data-model-link.spec.ts

- **GIVEN** you are an admin on the Objects index
- **WHEN** you press Manage object types
- **THEN** OpenRegister's schema list for the dossiq register SHALL open
