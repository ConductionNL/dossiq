---
kind: config
depends_on: [documents-on-the-case]
---

# Proposal: scan-verdict-on-the-row

Competitor gap register, row 4.19 "Virus scan and integrity check"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner nextcloud, slug
`scan-verdict-on-the-row (dossiq)`, size S. This is dossiq's half; the
scan is the platform's.

## Why

A document on a case carries a hash and no verdict.
`informatieobject.integrity` holds the hash and
`register.d/50-subsidie.json#bewijsstuk.fileHashSha256` another; the virus
scan is Nextcloud's `files_antivirus` on the node, and its result is not
shown where a handler decides to open an attachment.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/document/infrastructures/tika.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- The Files tab row shows a Scan column: the status `files_antivirus`
  recorded for the node, with the scan time; Not scanned when the app is
  absent or has not reached the file.
- The properties dialog shows the hash beside it.
- Nothing scans in dossiq.

## Ownership

dossiq builds the column declaration and a formatter that reads the
verdict. It consumes Nextcloud `files_antivirus` (platform) for the
verdict, `documents-on-the-case` (dossiq, open) for the row, and the Files
tab columns from nextcloud-vue, to be specified in nextcloud-vue under the
register's slug `files-browser-columns` (row 4.8).

## ADRs

- Company ADR-022: the scan is the platform's; dossiq shows it.
- Company ADR-102: an absent scanner is reported as Not scanned, never as
  clean.

## Capabilities

- Modified: `document-zaakdossier`: the row shows the scan verdict.

## Impact

`src/manifest.json` `#CaseDetail` Files columns; one formatter in
`src/formatters/`; a small read of the `files_antivirus` status in
`lib/` behind an app-installed check; one e2e spec.
