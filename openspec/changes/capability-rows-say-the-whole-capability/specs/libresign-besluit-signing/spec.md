# libresign-besluit-signing

## ADDED Requirements

### Requirement: The signed document comes back onto the case (REQ-CRS-31)

When a signature request completes, the signed PDF SHALL be filed on the case as a new document version, and the decision SHALL point at the signed version.

Rows: `4.12` (dossiq matrix).

#### Scenario: A signed decision
- **GIVEN** a decision sent for signing
- **WHEN** the signer signs it
- **THEN** the case documents hold the signed PDF and the decision refers to it
