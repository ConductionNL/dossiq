# Design: task-search-fields

## D-1. Declared fields, mapped once

The sidebar declares `case`, `assignee`, `dueFrom`/`dueTo`, `state`,
`priority`. `useEngineTaskStore` maps them to the inbox's named arguments
(`subjectUuid`, `assignee`, the due-window pair, `state`, `priority`).

## D-2. Measure the inbox before declaring

Task 1.1 lists which of the five the inbox answers today. A missing one is
not faked client-side: the field stays off the sidebar and the gap is
written to openregister with the parameter name. A version mismatch must
log at ERROR naming the parameter, the way `remove-casetask` 6.4 does.

## D-3. Lenses and fields compose

A lens sets its filter; a field narrows within it. The URL carries both so a
filtered view can be shared.
