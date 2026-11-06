# Proposal: organisations-legal-form-short

## Why

Board DqOrganisaties reads Rechtsvorm "BV" where the KvK stores "Besloten Vennootschap". The coordinator decided
(24 Oct) on a display map in config that follows the board.

## What changes

The Organisations list's Legal form column uses nextcloud-vue's `valueMap` formatter (feat/value-map-formatter,
24t1m), mapping Besloten Vennootschap → BV, Naamloze Vennootschap → NV, Vennootschap onder firma → VOF and
Commanditaire Vennootschap → CV. Eenmanszaak, Vereniging and Stichting show as stored. Config only, stacked on
r25/organisations-count-pills (same column).
