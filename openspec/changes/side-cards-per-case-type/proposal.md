# Side cards per case type

## Why

Ruben, 18 October 2026: side cards are configurable per case type. The domain
boards (DqSociaalDomein, DqAanbesteding, DqBezwaar) draw their own cards beside
the case near the top and leave the generic ones out; the case page drew one
column for every case, so the domain cards sat under deadline, requester,
handling and work instruction.

## What changes

- The caseType schema (1.24.0) gains `sideCards`: the ids of the case page's
  side cards a case of this type shows, in order. Empty or absent shows the
  declared column, so nothing changes for an existing case type.
- The simple case page reads it with `sideColumnFrom:
  "@context.caseType.sideCards"` (nextcloud-vue detail-side-column-from).
- jeugdwet, aanbesteding and bezwaar-beroep (1.2.0) list the cards their boards
  draw; the register-seeded Bezwaar case type carries the same list.

## Depends on

nextcloud-vue detail-side-column-from. On an older release the key is ignored
and every case shows the declared column, as today.
