---
kind: code
---

# Proposal: deadline-definitions-board-table

## Summary

r31 DqBeheerGeavanceerd: AWB-termijndefinities drew a row of pills per definition and repeated the card description; the board draws one table (Grondslag, Duur, Versie, Telling, Gesloten dag, Geldig, Staat, Nieuwe versie) with a secondary "Nieuwe termijndefinitie". The case type column shows when a definition names one. Also: the first-run titles read in the text ink (thematiq painted them link blue, underlined), the readiness list radius is 8, and a present prerequisite / active definition sits on a light success fill.

## Scope

- DeadlineDefinitionsTab.vue, FirstRunTab.vue, PrerequisitesTab.vue, l10n, a vitest.

Also (r31 follow-up): the mandate matrix picks its section with a labelled 560px field (was a caption and four chips under a duplicate description), its buttons are secondary as the board's, and a mandate status reads in words ("determined" -> Vastgesteld).
