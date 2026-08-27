# decision-making-surface

## ADDED Requirements

### Requirement: Decision-making is owned by decidesk and surfaced on the case as a leaf

Per ADR-019 and ADR-066, decision-making is decidesk's capability and is
surfaced wherever needed through the shared OpenRegister integration registry.
Procest SHALL surface decision-making **only** as a render-surface leaf on the
case, and SHALL NOT host its own decision-making pages.

The leaf is a render-and-read channel (ADR-066): it SHALL expose no verb that
invokes a business action in decidesk. Cross-app commands continue to travel as
typed `*RequestedEvent` / `*ConcludedEvent` contracts (ADR-041).

#### Scenario: The case carries the decision-making leaf

- **GIVEN** a user opens a case's detail page
- **THEN** a decision-making tab renders decidesk's registered leaf for that case
- **AND** it lists the proposals, advice and decisions linked to the case

#### Scenario: Procest hosts no decision-making pages

- **GIVEN** the procest manifest
- **THEN** no `/besluitvorming/agenda` and no `/besluitvorming/vergaderingen/:id` page exists
- **AND** `AgendaCompilerView` and `VergaderingDetailView` are not registered as components
- **AND** `src/manifest.d/50-besluitvorming.json` is absent

#### Scenario: The leaf degrades quietly when decidesk is absent

- **GIVEN** an instance where decidesk is not installed
- **WHEN** a user opens a case's decision-making tab
- **THEN** a quiet unavailable notice is rendered
- **AND** the case detail page itself continues to work

### Requirement: Objection advisory committees and approval routes live in decidesk

The `bezwaaradviescommissie` (objection advisory committee) and `parafeerroute`
(approval route) surfaces SHALL be administered in decidesk, alongside its
governance-body and routed-document models. Procest SHALL NOT declare index or
detail pages for either.

#### Scenario: Procest hosts no committee or approval-route pages

- **GIVEN** the procest manifest
- **THEN** no `/settings/bezwaar-committees`, `/settings/bezwaar-committees/:id`, `/settings/parafeerroutes` or `/settings/parafeerroutes/:id` page exists
- **AND** no menu entry points at either

#### Scenario: Committee data remains reachable from a case

- **GIVEN** a bezwaar case with an advisory committee assigned
- **WHEN** a user opens that case
- **THEN** the assigned committee is visible through the decision-making leaf
