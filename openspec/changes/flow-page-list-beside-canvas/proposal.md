# Proposal: flow-page-list-beside-canvas

## Why

Board DqZaaktypeFlow draws a case type's flow on a page of its own, opened with View as flow or Add task. The header shows the case type, the version and a status pill, with Check, More and Save. A Canvas | List switch sits under it ("The same flow and the same steps as on the canvas; what you change here is there too"). The List shows the trigger first ("When: Case created"), then the statuses and steps in order, numbered, each with its kind pill (Status, Task, Message, Action), move up/down, edit and delete. A side panel edits the chosen step (step name, name of the status, steps in this status, next status, deadline in this status, Done, Delete step).

On the demo stack (24 Nov 2026) `/settings/case-types/{id}/flow` shows the older editor: a grid canvas with an Elements palette and Edit/Download/Import buttons. It has no list view and no side panel in the board's shape, so a person who prefers a list, or uses a screen reader, cannot read the flow in order.

## What changes

- The case type flow page gets the board's header row (title, version pill, Check, More, Save) and a Canvas | List switch.
- The List view shows the same flow as an ordered list: the trigger, then each status with its steps, numbered, with a kind pill and move, edit and delete per row. A change in either view is the same change: both read and write one flow definition.
- The step side panel follows the board's fields and buttons, in both views.
- The page is manifest configuration over the library's flow widgets (nextcloud-vue), not a dossiq component. Where the library lacks the list view, it is built there.

## Out of scope

- New step kinds or engine changes.
- The organisation-wide flows page (/flows), which keeps its own board.
