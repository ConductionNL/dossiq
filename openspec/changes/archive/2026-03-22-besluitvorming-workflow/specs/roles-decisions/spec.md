## MODIFIED Requirements

### Requirement: Decision CRUD

The system SHALL support creating, reading, updating, and deleting formal decisions linked to cases. Decisions represent administrative determinations with potential legal effect, corresponding to ZGW `Besluit`. The besluitvorming workflow activates this capability: when a voorstel reaches "besloten" status, a decision object is created linking the college's determination back to the case.

**Tier**: V1

#### Scenario: Create a decision on a case

- **WHEN** the decision maker "dr.k.bakker" records a decision on case #2024-042 "Bouwvergunning Keizersgracht"
- **THEN** the system SHALL create a decision object with `decidedBy`: "dr.k.bakker" and `decidedAt`: current timestamp
- **AND** the decision SHALL appear in the Decisions section of the case detail view

#### Scenario: Create decision from voorstel workflow

- **WHEN** the secretariaat clicks "Besluit registreren" on a voorstel with status "geaccordeerd"
- **AND** enters: besluit tekst, ingangsdatum, besluittype
- **THEN** a decision object SHALL be created via the existing decision schema
- **AND** the decision SHALL be linked to the parent case of the voorstel
- **AND** the voorstel status SHALL change to "besloten"
- **AND** the case activity timeline SHALL show: "Besluit vastgesteld: [tekst]"

#### Scenario: View decisions on case detail

- **WHEN** the user views the case detail for a case with 2 decisions
- **THEN** both decisions SHALL be displayed in the Decisions section sorted by decidedAt descending
- **AND** each decision SHALL show: title, decided by, decided at, validity period, decision type

#### Scenario: Delete a decision

- **WHEN** the user deletes a decision
- **THEN** the decision object SHALL be removed from OpenRegister
- **AND** the audit trail SHALL record the deletion
