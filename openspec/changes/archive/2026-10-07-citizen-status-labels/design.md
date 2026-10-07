# Design: citizen-status-labels

## D-1. Optional, with a fallback

`publicLabel` empty falls back to `name`; `publicDescription` empty falls
back to nothing (the public page shows no description rather than an
internal one). One helper `StatusType::publicLabelOf(row)` in both readers.

## D-2. Two readers, one helper

`PublicStatusPage` and the portal contribution call the same helper. The
ZGW mapping writes `statustekst` from it.

## D-3. Authoring

The status editor on the case type page (REQ-CT-10) gains the two fields
under a heading "What the applicant sees".
