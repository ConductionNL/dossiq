# Proposal: case-type-rules-as-rows

## Why

The case type's Rules tab drew OpenRegister's rule inventory as a five-column table: technical keys
("assignedGroupPublicName"), one generic sentence per kind, a flow known only by its uuid, and a table wider than its
card. Board DqZaaktypeRegels draws "Regels op de zaak" as a titled card of rows: a title, a line saying what the rule
does, and a pill (Berekend, Aan).

## What changes

- The widget asks for the titled card; its definition carries the board's title and subtitle.
- Each rule is a row: titled by the field it writes (the schema property's title, else its key in words), with the
  first sentence of that field's description (else the kind's description) and a pill: Calculated for a calculation,
  On or Off for any other rule. A rule known only by an id is titled by its kind.
- Trying a rule against a case stays, behind "Try a rule against a case", with the rule picked from the list.

The board's conditional case-type rules ("Als Bouwkosten groter is dan € 100.000, dan taak …") are a separate
proposal (case-type-conditional-rules).
