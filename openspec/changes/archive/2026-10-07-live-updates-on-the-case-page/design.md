# Design: live-updates-on-the-case-page

## D-1. Subscribe to the object, not the schema

`DeelzaakDetail` subscribes to its one object; `#CaseDetail` does the same
for `@objectId`, and additionally to runs whose subject is the case. The
board's schema-wide subscription is not the model here.

## D-2. Refresh through the existing fetch

An event triggers the store's existing fetch for the case, the same way
`DeelzaakDetail` reloads. No partial patching of state.

## D-3. The poll goes

`pollSeconds` removed from `case-flow-runs`; a vitest asserts no widget on
`#CaseDetail` carries a poll.
