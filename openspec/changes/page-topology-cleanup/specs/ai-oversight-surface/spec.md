# ai-oversight-surface

## ADDED Requirements

### Requirement: AI oversight is owned by hermiq

AI oversight — human-in-the-loop approval, algorithm registration and the
governance record for AI-assisted processing — SHALL be administered in hermiq,
alongside its existing approvals, algorithm-register and compliance surfaces.
Procest SHALL NOT declare AI-oversight index or detail pages.

#### Scenario: Procest hosts no AI-oversight pages

- **GIVEN** the procest manifest
- **THEN** neither `/settings/ai-oversight` nor `/settings/ai-oversight/:id` exists
- **AND** no AI-oversight menu entry exists

#### Scenario: Oversight records for procest-originated AI actions remain reachable

- **GIVEN** an AI-assisted action recorded on a procest case
- **WHEN** an administrator opens hermiq's oversight surface
- **THEN** that action's oversight record is listed with its originating case reference
