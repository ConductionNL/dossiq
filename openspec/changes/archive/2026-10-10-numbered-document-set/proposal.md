# Proposal: numbered document set

## Why

Decision 182 makes procedures configuration and code generic. Capability C05 in hydra's
`procedures-are-configuration` design is "numbered document series: gives documents in a set a
stable order and number". Lane L1 (#3624) built the first half: one running number per
organisation and year for a document that leaves the building (`DocumentSeriesNumberer`). The
second half, numbering every document of one set in a stable order, still lives inside
procedure-named code: `BeroepDossierExport` numbers a court dossier, `DossierZipExporter` and the
Woo inventory list number their own sets.

## What changes

- `Service/DocumentSeries/SeriesDefinition`: one series' configuration, read from
  `documentSeries.<series>` on the case type, refusing values the schema refuses.
- `Service/DocumentSeries/DocumentSetNumberer`: numbers a set in the configured order, with the
  `renumber` or `keep` gap policy, and makes a filename that sorts like the number.
- `register.d/39-document-series.json`: `order`, `width`, `gapPolicy` and `numberField` on a series.
- Spec `numbered-document-series`: REQ-NDS-004 and REQ-NDS-005.

Nothing moves yet. `BeroepDossierExport`, `DossierZipExporter` and the Woo inventory move onto
this capability in later refactor PRs (lane R5), after the build lanes' open PRs on them land.

## Impact

New classes only, unit-tested on a neutral fixture case type. No route, no screen, no migration.
