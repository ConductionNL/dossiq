# Proposal: case-type-versions-card

## Why

Board DqZaaktype draws the side Versies card with a state per version (Concept, Actief, Vastgezet) and, on a
published version, "Een gepubliceerde versie verandert niet meer. Wijzigen maakt een nieuwe versie." above
"Nieuwe versie maken" across the card. Live reads Gepubliceerd/Concept and ends every version with "Publiceren",
greyed out on a published one ("Deze versie is al gepubliceerd").

## What changes

`side-case-type-versions`:

- pill: Draft (isDraft), Frozen (nl "Vastgezet": closed for new cases, validUntil set), else Active. The isDraft
  field labels stay as the fallback for a library without `pill.cases`.
- footer: on a draft "Publish" (CaseTypePublishDialog); otherwise the note and "Make a new version"
  (CaseTypeNewVersionDialog, the same dialog as the More menu's "New version"), wide.

The board's "Activeren" on a draft is not built: a case type version has no activation step apart from publishing.

LAND TOGETHER with nextcloud-vue `feat/record-list-pill-cases-footer-choice` (footerAction list, note, wide,
pill.cases; schema 2.154.0). An older library draws no footer action for the list.

## Reach

Config and three l10n keys.
