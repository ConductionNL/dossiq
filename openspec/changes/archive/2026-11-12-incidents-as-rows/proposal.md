# The case's incidents as rows

## Why
Board DqZaakTaken draws "Incidenten" as rows: the description, then when it happened, who reported it and who owns
it, and the state pill, with a separator under the title. r25b (24 Oct) measured a table there instead.

## What changes
The simple profile's `case-incidents-card` becomes a `record-list` in `rows`. The order stays `eventDate` ascending, and
the recording moment stays on every row, so REQ-CM-53 D-6 (the delay between event and recording stays visible) still
holds. Add stays, as a header action opening the incident form. The full profile's table is unchanged.
