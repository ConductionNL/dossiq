# Proposal: case-type-version-pill

## Why

Boards DqZaaktype, DqZaaktypeOverzicht and DqZaaktypeVelden draw the case type's status pill as "Versie 4
gepubliceerd" (green) or "Versie 5 concept" (amber). The page said "Gepubliceerd" or "Concept" without the version.

## What changes

`CaseTypeDetail.statusPill.labels`: "Version {version} published" / "Version {version} draft" (nl "Versie {version}
gepubliceerd" / "Versie {version} concept"). Needs nextcloud-vue `feat/detail-pill-label-tokens` (590ce611a8):
LAND TOGETHER, before it the pill shows the token literally.

## Reach

Config and l10n.
