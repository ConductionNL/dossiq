# The case's planned actions and incidents as the board's ruled rows

## Why
DqZaakTaken (r26c, 25 Oct) draws "Wat hierna komt" and "Incidenten" as rows under a rule above the first row. "Wat hierna komt" has a calendar tile, the action, its owner and the planned date at the end, plus "2 gepland" in the title row. Live drew the planned actions as a table and the incidents without a rule above the first one.

## What changes
- `case-planned-actions-card` becomes a record-list in `rows`, with:
  - `ruleAbove`, a calendar icon, title `{label}`, meta "Owner {owner|user}" and trailing `{plannedFor|date}`;
  - headerNote "{total} planned";
  - the same filter and order (the first row is still the next action);
  - Add kept as a header action.
- `case-incidents-card` gets `ruleAbove`.

Config and en/nl keys only.
