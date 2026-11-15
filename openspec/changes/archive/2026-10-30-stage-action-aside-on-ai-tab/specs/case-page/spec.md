## ADDED Requirements

### Requirement: The stage action stands aside where the what-now card does

The case page SHALL not draw the stage's primary action in the header beside a tab where the what-now card stands aside; the action SHALL stay under More.

#### Scenario: the AI oversight tab
@e2e exclude config pinned by tests/vitest/stageActionAsideOnAiTab.spec.js; behaviour tested in nextcloud-vue

- **GIVEN** a case in progress
- **WHEN** the handler opens the AI oversight tab
- **THEN** the header SHALL show Bericht, Bewerken, the layers button and Meer, and no Volgende stap
