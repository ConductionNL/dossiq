# Proposal: zgw-mapping-readable-names

## Summary

The ZGW API mapping table (admin, Geavanceerd tab, board DqBeheerGeavanceerd) listed each mapping under its stored key: "catalogus", "caseType", "roltype", "decision". The coordinator asked (24 Oct) for readable labels. Each stored key now reads as a translated name ("Catalogus", "Zaaktype", "Roltype", "Besluit"); a key nobody named reads as itself.

## Scope

- `src/views/settings/zgwResourceNames.js` (new), `src/views/settings/ZgwMappingSettings.vue`, l10n, vitest. No schema change.
