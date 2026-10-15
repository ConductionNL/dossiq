# Proposal: case-page-reads-in-words

## Summary

Two raw values leave the case page: the initiator type reads "Bedrijf" instead of "company", and a closed case's deadline tile reads "Afgehandeld" instead of "24 dagen te laat".

## Why

Zuiddrecht live round 3 (14 Oct) found both on the case page. `initiatorType` is an enum without `x-enum-labels`, so the data widget printed the stored value. The deadline tile is the library countdown, which counted days past the deadline also on a case in its final status.

## What changes

- `initiatorType` in the case schema gets `x-enum-labels` (Person, Company, Contact; Dutch already in `l10n/nl.json`). Register `info.version` 0.20.43 to 0.20.44.
- The deadline tile (`case-tile-deadline`, in the manifest and the simple layout) sets `settledField: isFinalStatus` and `settledLabel: Handled`. It takes effect on the nextcloud-vue release that ships `settledField` (ConductionNL/nextcloud-vue#1607); until then the library ignores the two keys.

## Elsewhere

- The English toast "Moved to ..." is a library string: Dutch in ConductionNL/nextcloud-vue#1605.
- The label drawn over the placeholder in the timeline filters is a theme rule: ConductionNL/thematiq#1308.
