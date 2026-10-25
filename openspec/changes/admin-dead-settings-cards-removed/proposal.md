# Proposal: admin-dead-settings-cards-removed

## Summary

Two admin cards shipped placeholder text ("This panel will host …"). A stub must not ship (coordinator, 24 Oct). Measured: nothing in dossiq reads the settings behind them (ConfigKeys documents this for both blocks: only the Vue form and the allowlist mention them).

- **Mandaatmatrix: systeeminstellingen** (Geavanceerd tab): its three settings (Decidiq connection, default stand-in extension days, auto-activate an approved import) have no reader. The card is removed. Its config keys stay registered, so stored values are kept for when a reader is built. What the card was meant to hold is listed for the coordinator as a feature: role hierarchy, Decidiq imports, stand-in assignments (lane DQ-L9 TRIAGE.md).
- **Adviseringsbeheer** (Systeem tab, drawn on DqBeheerinstellingen): the placeholder line is removed. The card's fields stay, because the board draws them. Wiring their readers (default term at creation, warning offset and bottleneck threshold in the n8n workflows, external response base URL) is listed as a feature.

Stacked on admin-advanced-tab.

## Scope

- `src/views/settings/AdminRoot.vue`, `src/views/settings/adminFields.js`, tests. No schema change.
