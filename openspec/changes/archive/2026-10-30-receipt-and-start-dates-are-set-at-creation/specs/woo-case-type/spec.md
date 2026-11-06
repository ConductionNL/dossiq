## MODIFIED Requirements

### Requirement: WOO-specific intake form
The system MUST provide intake fields specific to WOO requests as property definitions on the zaaktype. The ontvangstdatum is not one the case worker fills: dossiq answers it when the case is created (REQ-TERM-042).

#### Scenario: Required intake fields
@e2e exclude The hidden Ontvangstdatum is asserted in tests/vitest/wooReceiptDateOffCreateForm.spec.js; the seeded definitions are data, not a create run.

- **GIVEN** a new WOO Verzoek case being created via `CaseCreateDialog.vue`
- **WHEN** the case worker fills in the intake form
- **THEN** the form MUST include these property definitions:
  - **Verzoeker naam** (string, required)
  - **Verzoeker contactgegevens** (email/phone, required)
  - **Verzoeker type** (enum: burger/journalist/organisatie, required)
  - **Onderwerp** (text, required -- topic of the request)
  - **Periode van** / **Periode tot** (date range the request covers)
  - **Bestuurlijke aangelegenheid** (text -- administrative matter)
  - **Gewenste vorm** (enum: papier/digitaal/inzage, default: digitaal)
- **AND** **Ontvangstdatum** (date) MUST NOT be required: dossiq answers it on creation from the receipt, and keeps an answer an integration supplied
- **AND** the create form MUST NOT ask for the **Ontvangstdatum** (`hideOnCreate`, REQ-PDM-16)

#### Scenario: Intake form validation
- **GIVEN** a case worker submitting the WOO intake form
- **WHEN** required fields are missing
- **THEN** the system MUST highlight missing fields with validation errors
- **AND** block case creation until all required fields are filled

#### Scenario: Verzoeker linked to contact record
- **GIVEN** a WOO intake with verzoeker details
- **WHEN** the case is created
- **THEN** the verzoeker MUST be linked as a role (using the `role` schema with roleType "Verzoeker")
- **AND** if the verzoeker matches an existing contact in OpenRegister, the system MUST suggest linking to the existing record

#### Scenario: Intake auto-generates ontvangstbevestiging task
- **GIVEN** a WOO case is created
- **WHEN** the case enters the "Ontvangst" stage
- **THEN** a task MUST be auto-created: "Verstuur ontvangstbevestiging" with a 2-day deadline
- **AND** the task MUST reference the verzoeker's contact details

#### Scenario: Intake from external channel
- **GIVEN** a WOO request arrives via email, post, or a web form through OpenConnector
- **WHEN** the request is routed to Dossiq
- **THEN** the system MUST pre-fill the intake form with available data from the incoming message
- **AND** flag any missing required fields for manual completion
