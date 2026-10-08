# Design: planned-case-series

## D-1. A series is one flow, not many

The follow-up document already writes one scheduled flow with five cron
fields. A recurrence is a different set of cron fields on the same document:
monthly is `0 8 <day> * *`, yearly is `0 8 <day> <month> *`. Quarterly and
half-yearly list months (`1,4,7,10`). Nothing else in the document changes,
and `runAs` stays as it is (ADR-099).

## D-2. The sweep decides when a series is spent

Today the sweep switches a flow off after its first fire, because five cron
fields cannot say once. With a recurrence set the sweep reads `series.until`
(a date) or `series.count` (an integer) from the document and switches the
flow off when either is reached. A document with neither is a single
follow-up and keeps today's behaviour. The rule lives in the pure document
class so it is testable without an instance.

## D-3. Occurrences know their series

Every case an occurrence creates carries `handoffSource` =
`planned-series:<flowId>`. The Related tab lists the series and its next
occurrence from the flow, and the cases already created from the case
register. No new schema.

## D-4. Stopping a series

Stop is a header action on the series row: it switches the flow off with a
recorded reason. Cases already created stay.
