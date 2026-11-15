# Proposal: admin-advanced-tab

## Summary

The System and Integrations tabs of dossiq's admin page ran on far past their boards (DqBeheerinstellingen, DqInstellingen): 11 and 7 more cards, set once or by a specialist. Users keep features, so nothing is removed. The coordinator decided on 24 Oct, matching Ruben's "under Geavanceerd" call for the Catalogus, to give them their own last tab, "Geavanceerd" (board DqBeheerGeavanceerd).

- Moved from Integrations: ZGW API mapping, KCC-werkplek, decision tables (DMN), financial integration (penalty callback), StUF-ZKN endpoints and audit log, Store register.
- Moved from System: first run, prerequisites, what shipped with dossiq, tenant onboarding, case type management, AWB terms, queue urgency, mandate matrix (administration and settings), archiving, Woo review reports.
- `lib/Settings/connections.json`: the connection rows whose card moved link to `?tab=advanced#section-…`.

## Scope

- `src/views/settings/AdminRoot.vue`, `lib/Settings/connections.json`, tests (vitest adminAdvancedTab, e2e integrations-page links). No schema change.
