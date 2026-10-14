---
kind: code
---

# Proposal: portal-woo-request-form-takes-dutch-input

## Summary

A resident who filed a Woo request through the portal on 14 October 2026 could not send it. The period fields were free text and the route only took `jjjj-mm-dd`, so "01-01-2026" was refused with the English sentence "A period date must be written as YYYY-MM-DD". The overview tile then said only "Niet alles is goed ingevuld" without naming a field. After the request went in, the confirmation read "Wij tellen de termijn vanaf 2026-10-14T12:34:29+00:00".

The form now asks the period as dates, the route reads a day written day first as well, every refusal names its field in Dutch, and the confirmation names its dates the way the letters do ("14 oktober 2026").

## Why

Found in the live test of the Zuiddrecht demo on 14 October 2026 (screens b3-woo-tile-vals-submitted, b3-woo-dossier-submitted, b3-woo-period-field).

## What changes

- `CitizenManifest`: `periodeVan` and `periodeTot` declare `input: date`, so portaliq asks day, month and year and sends `jjjj-mm-dd`. The requester kind was already a choice with the Dutch words Inwoner, Journalist and Organisatie over the stored values; a test now pins that.
- `WooRequestForm`: a date is read as `jjjj-mm-dd` or day first (`14-10-2026`, `1-3-2026`, `1/3/2026`, `1.3.2026`) and kept as `jjjj-mm-dd`. The requester kind is also read from its Dutch word. Every refusal of the form carries the field it is about and an English catalogue sentence.
- `PortalWooRequestController`: a refusal answers `errors: {<field>: <sentence>}` in Dutch (English when the forward asks for it), which portaliq shows on that field.
- `WooWrittenCase`: the answer adds `termStartsDay`, `deadlineDay` and `receivedDay` beside the stored values; the confirmation names those.
- `ReadableDay` (new): the day formatter the letters use since `letters-print-every-date-the-same-way`, moved out of `TermLetters` so the confirmation and the letters write a date the same way.

Portaliq keeps a declared `input` hint on an endpoint action only after its own change (portaliq `endpoint-action-forms-keep-their-field-shape`); until then the period fields stay text and the route still reads a day written day first.

## Screens

WooVerzoek (`screens-src/zuiddrecht/`): the period step asks dates as day, month and year. No layout change.
