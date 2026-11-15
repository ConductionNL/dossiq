## Why

A correction of a signed beschikking is now a numbered successor (REQ-BES-012, built in
`frozen-beschikking-and-numbered-successor`): the original keeps its text and its number, the
successor carries its own number, and the two point at each other. The data is there. The case
page does not show it yet: the drawn board `DqZaakBesluiten` shows one beschikking per case and
no chain, so a handler who opens a case with a correction cannot see which beschikking is in force
and which was replaced.

## What Changes

- The case page lists every beschikking of the case in issue order, each with its number, and
  says per beschikking whether it is in force or which beschikking replaced it.
- A handler can start a wijzigingsbeschikking or an intrekkingsbeschikking from a signed
  beschikking on that page, through `POST /api/beschikkingen/{id}/successor`.
- The board is drawn first (decision 162); the UI is built against it.

## Capabilities

### Modified Capabilities

- `beschikking-generatie`: a requirement for showing the chain on the case.

## Impact

- The case page's decisions section (board `DqZaakBesluiten`).
- No backend change: the endpoint, the numbering and the pointers exist.
