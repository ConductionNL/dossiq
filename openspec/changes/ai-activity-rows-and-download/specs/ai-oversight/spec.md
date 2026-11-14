## ADDED Requirements

### Requirement: The AI oversight card offers its records for download to the oversight role

The AI oversight tab SHALL list hermiq's records about the case and SHALL offer their download only to members of the ai-oversight group, with a line saying so. It SHALL offer no Add.

#### Scenario: an oversight member on the AI oversight tab
@e2e exclude config pinned by tests/vitest/aiActivityRowsAndDownload.spec.js; behaviour tested in nextcloud-vue and hermiq

- **GIVEN** a member of ai-oversight who may open the case
- **WHEN** they open the AI oversight tab
- **THEN** the card SHALL list the assistant's suggestions on this case
- **AND** offer Downloaden, with the line "Downloaden mag alleen wie de rol AI-toezicht heeft."
