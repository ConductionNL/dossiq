## ADDED Requirements

### Requirement: A question dossiq answers itself stays off the create form (REQ-PDM-16)

A property definition SHALL be able to declare `hideOnCreate`. A definition
with `hideOnCreate: true` SHALL NOT be asked on the form that creates a case,
because dossiq answers it at creation. The case form SHALL still show it once
the case exists. The `propertyDefinition` schema SHALL declare the field so
OpenRegister keeps it.

#### Scenario: The Woo receipt date is not asked when a Woo case is created
@e2e exclude The flag on both Woo seeds and its effect through the library's propertiesFromDefinitions are asserted in tests/vitest/wooReceiptDateOffCreateForm.spec.js. The rendering is the library's CnFormDialog, covered by nextcloud-vue's own tests.

- **GIVEN** the Woo case type, whose `receiptDate` definition carries `hideOnCreate: true`
- **WHEN** a handler creates a Woo case
- **THEN** the create form SHALL NOT ask for the Ontvangstdatum
- **AND** the case form SHALL show the Ontvangstdatum dossiq filled in once the case exists

#### Scenario: A definition without the flag is asked as before
@e2e exclude Asserted in tests/vitest/wooReceiptDateOffCreateForm.spec.js, which keeps every other Woo question on the create form.

- **GIVEN** a definition that does not set `hideOnCreate`
- **WHEN** a handler creates a case of that type
- **THEN** the create form SHALL ask for it
