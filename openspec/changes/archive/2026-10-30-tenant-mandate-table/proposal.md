# Proposal: tenant-mandate-table

## Why

DqTenant's mandate card is a table per role with these columns:
- "Besluiten tot": € 5.000, € 50.000 or Onbeperkt;
- "Bezwaar beslissen": Ja or Nee;
- "Handhaving": Nee, Waarschuwing or Ja.

The card listed a mandate reference, a start date and a signatory. The tenant mandate held nothing that says what a role may decide.

## What changes

- `tenantMandate` gains `terms`:
  - `plafond`, the ceiling in euros, where empty means unlimited;
  - `decisionTypes`;
  - `enforcement` (none, warning or full).
  - Schema version 1.1.0.
- The overview card shows Role, Decides up to (currency, "Unlimited" when empty), Decides objections (Yes when the decision types include bezwaar) and Enforcement (No, Warning or Yes). The full Mandates tab keeps its dates and signatory.
- Configuration only. The column keys `emptyLabel`, `valueLabels` and `contains` come from nextcloud-vue `feat/r24t-column-value-labels` (24t). Until then the cells show the raw values.
- Dutch strings are added for the three new labels. The l10n .js catalogues need regenerating, as for every lane that edits the json.
