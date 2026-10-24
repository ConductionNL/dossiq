# Proposal: case-type-history-reference

## Why

Board DqZaaktype's Historie side card ends in "Kenmerk ZT-OMV-REG", the case type's identifier.

## What changes

`side-case-type-history` sets `content.footnote: "Reference {identifier}"` (nl "Kenmerk {identifier}"), drawn by
nextcloud-vue `feat/audit-trail-footnote` (5466f0fe25). An older library ignores the key.

## Reach

Config and l10n.
