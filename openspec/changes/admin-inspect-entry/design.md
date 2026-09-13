# Design: admin-inspect-entry

## D-1. One action, two targets

`case-inspect` is an action group with `adminOnly`: `open-modal` target
`CnObjectMetadataModal` with `objectId: @objectId`, and `navigate` to
`/apps/openregister/#/flows/runs?subjectUuid=@objectId` (the URL the
`case-flow-runs` rows already open).

## D-2. Visibility

`adminOnly` on the group; a handler never sees Inspect.
