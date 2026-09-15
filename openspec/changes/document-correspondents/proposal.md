---
kind: config
depends_on: [documents-on-the-case]
---

# Proposal: document-correspondents

Competitor gap register, row 5.12 "Document senders and recipients"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

A letter on a case does not say who sent it or who received it. The
`dispatch` schema in `lib/Settings/dossiq_register.json` declares an
involved party with a relationship type "afzender/geadresseerde", and
`zaaktypeInformatieobjecttype.direction` says inbound or outbound per type.
Both are schema only: nothing writes a dispatch when a beschikking goes
out, nothing writes a sender when mail intake files an attachment, and the
Files tab (`#CaseDetail/case-files`, the `files` leaf) shows name, size and
date.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/document/entities/document.py` (direction)
(`_round2/compare/M1-functionality.md`).

## What changes

- `sender` and `recipient` on the document projection that
  `documents-on-the-case` declares for a Files node on a case, each a
  reference to a party or a free-text name, plus `direction`.
- The beschikking delivery writes `recipient` and `direction: outbound`
  when it files the letter; the mail intake writes `sender` and
  `direction: inbound` from the message's From header.
- The Files tab shows Sender and Recipient as columns.
- The `dispatch` schema is retired: its two fields live on the projection.

## Ownership

dossiq builds the two fields, the two writers and the retirement. It
consumes `documents-on-the-case` (dossiq, open, 2 of 13 tasks) for the
projection, integriq's mail intake for the inbound write (the start-a-case
offer of `leaf-integrations`, shipped), and the Files tab columns from
nextcloud-vue, to be specified in nextcloud-vue under the register's slug
`files-browser-columns` (row 4.8). Until that lands the two fields are
visible in the document's properties dialog.

## ADRs

- Company ADR-031: correspondents are declared fields, not a service.
- Company ADR-075: one document channel; the delivery that files the
  letter is the one that writes the recipient.

## Capabilities

- Modified: `document-zaakdossier`: a document knows its sender and
  recipient.

## Impact

The projection schema in `documents-on-the-case`; `lib/Service/Beschikking/`
delivery; the intake listener of `leaf-integrations`;
`lib/Settings/dossiq_register.json` (`dispatch` removed, with its seed);
`src/manifest.json` `#CaseDetail` Files columns.
