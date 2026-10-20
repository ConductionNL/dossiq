# case-type-add-rule

## Why

Board dossiq/DqZaaktypeOverzicht's Rules card has "Regel toevoegen". OpenRegister has no endpoint that adds a rule: its
rule inventory (`GET /api/schemas/{schema}/rules`) is derived on every read from the schema's configuration
(calculations, state-field rules, lifecycle conditions) and from the flows triggered on the schema, and the rules API
only lists, switches, evaluates and replays them. The rule the board draws, a condition with an action ("Als
Bouwkosten groter is dan € 100.000 dan taak Toets door constructeur toevoegen"), is a flow. So adding a rule is creating
a flow, which the flow editor already does; no OpenRegister endpoint is needed.

## What changes

- The Rules card carries "Add rule", which opens a new flow (`FlowDetail` with id `new`).
