## ADDED Requirements

### Requirement: A handler asks the case type's decision table for advice on the case (REQ-LHS-CASE-01)

On a case whose case type names an advice decision table, the case page SHALL offer an action that collects the inputs the table declares (for the enforcement strategy: severity, behaviour and actor type), asks the table for a recommendation and shows it. The handler SHALL either accept the recommendation or choose another outcome with a mandatory reason. The choice SHALL be stored as its own object linked from the case, naming who decided, when, the recommendation, the chosen outcome and the reason.

#### Scenario: The handler accepts the recommended intervention

- **GIVEN** a handhavingszaak whose case type names the enforcement decision table
- **WHEN** the handler enters severity "ernstig", behaviour "calculerend" and actor type "bedrijf" and accepts the advice
- **THEN** the recommendation SHALL be stored and linked from the case with outcome equal to the recommendation
- **AND** the case's decisions widget SHALL list it
- @e2e tests/e2e/decision-table-advice.spec.ts

#### Scenario: An override needs a reason

- **GIVEN** a recommendation shown for a case
- **WHEN** the handler picks a different intervention without a reason
- **THEN** saving SHALL be refused with "Geef een reden voor de afwijking"
- @e2e tests/e2e/decision-table-advice.spec.ts

#### Scenario: A case type without a decision table shows no action

- **GIVEN** a case whose case type names no advice decision table
- **WHEN** the case page renders
- **THEN** the advice action SHALL NOT be shown
- @e2e tests/e2e/decision-table-advice.spec.ts
