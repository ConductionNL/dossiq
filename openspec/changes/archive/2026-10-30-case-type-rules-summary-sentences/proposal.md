# Proposal: case-type-rules-summary-sentences

## Why

Board DqZaaktypeOverzicht draws the Overview's Regels card as the case type's own rules: "Als Bouwkosten groter is
dan € 100.000 / dan taak Toets door constructeur toevoegen" with an Aan pill. Live lists the case schema's rule
inventory (one row per rule key with its kind), the same for every case type.

## What changes

- `RulesTab` gets a `summary` prop: only the case type's own rules as "Als … / dan …" rows with their pill, no row
  menus, no Add button, no schema inventory (not read), no trial; "No rules for cases yet" when there are none.
- `CaseTypeRulesWidget` passes `content.mode: "summary"` through as that prop.
- `case-type-rules-summary` becomes a `CaseTypeRulesWidget` card in summary mode; its "Add a rule" header action still
  opens the Rules tab.

## Reach

Two Vue components and the manifest. The Rules tab itself is unchanged.
