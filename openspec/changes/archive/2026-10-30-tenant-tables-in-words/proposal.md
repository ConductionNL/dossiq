# Tenant tables in words

## Why
DqTenant's Mandaten and Facturatie tables showed stored codes: "none", "warning", "full", "vergunning, bezwaar" and "case_refund" as a pill. The manifest named the words under `valueLabels` and `contains`, which no nextcloud-vue column reads.

## What changes
- Enforcement and billing event columns read as plain words through the `label` cell widget with `enum` + `enumLabels`.
- "Decides objections" reads "Yes" or "No" through a new generic formatter `containsLabel` (`formatterOptions: { token, yes, no }`): whether a list value holds a token.
- Needs nextcloud-vue fix/r34-object-list-keeps-formatter-options for the formatter options to reach the formatter on an object-list widget; before it the column shows the list as before.
