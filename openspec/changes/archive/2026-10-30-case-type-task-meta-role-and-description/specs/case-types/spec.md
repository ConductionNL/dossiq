# case-types Delta

## ADDED Requirements

### Requirement: A task card names its role and term, or says what the task is

The phases endpoint SHALL give each task `roleName`, the name of the role type its step routes to (`routingRule.roleType`
or `assigneeRole`), read in the reader's language from the case type's role types and those it inherits; empty when the
step routes to none. The Fasen en taken card of a task with a term SHALL read the role and the term; of a task without a
term, its description, or its role when it has none. The Nextcloud group SHALL show only for a task routed to no role.

#### Scenario: A task with a term

- **GIVEN** the step "Volledigheid toetsen" routed to role type Intaker with a term of 5 working days
- **WHEN** the Fasen en taken tab is drawn
- **THEN** its card reads "Intaker · 5 werkdagen"

#### Scenario: A task without a term

- **GIVEN** the step "Ontvangstbevestiging" with no role, no term and the description "Automatisch, bericht aan de aanvrager"
- **WHEN** the Fasen en taken tab is drawn
- **THEN** its card reads "Automatisch, bericht aan de aanvrager", not "0 werkdagen"
