# Design: task-defaults-to-case-handler

## D-1. The order is authored, fallback, handler, team, nobody

`resolve(primary, fallback, case)` keeps its two steps and adds
`case['assignee']` then `case['assignedGroup']`. The answer stays a string;
a group answers the group id, which the engine task accepts as an assignee
group. Nothing guesses: each step is a declared field.

## D-2. Opting out is explicit

`assignee: "none"` short-circuits to `''` before any step. The word is
reserved and documented in the `createTask` config shape.

## D-3. Same resolver, same behaviour in the flow node

`DossiqAskPersonNode` calls the same method, so a human step in a flow
inherits the default without its own change. One fixture pair proves the
node and the handler agree.
