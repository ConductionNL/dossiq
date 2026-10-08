# Design: task-dependencies-and-the-next-planned-action

## D-1. An offset from a predecessor is the only offset that survives a delay

A cumulative sum from the case start is right exactly once, on the day the
case opens. Every delay after that makes every later date wrong together.
Naming the predecessor means one date moves and the rest follow, which is
the behaviour the field `dependsOn` was already declared for.

## D-2. The old behaviour is the default, not a migration

An item that names no predecessor keeps counting from the case start. So
nothing has to be rewritten on the day this ships, and a case type adopts
the new shape item by item.

## D-3. A cycle is refused at authoring time

A cycle found at runtime is an infinite loop or a silently missing date. The
definition is saved by a person who can fix it, so that is where the check
belongs.

## D-4. A date with no owner is not a plan

The timeline says when; somebody has to do it. Resolving the assignee from a
role on the case rather than naming a person means the timeline survives
staff changes, which is the same reason `task-defaults-to-case-handler`
resolves rather than names.

## D-5. A planned action is typed, because the chain lives on the type

"Send the confirmation, then call after ten days, then close" is a property
of how the organisation works, not of this case. The type declares the
successor, so the chain is administered once and every case gets it.

## D-6. The chain is scheduled, not created in advance

Creating the whole chain up front produces a list of future actions that are
wrong as soon as one of them is skipped. Each completion schedules the next
one, so the plan is always one step long and always right.

## D-7. Blocked is derived, and it hides the task

A blocked task that still appears in a due list is noise, and noise is what
makes people stop reading the list. So the blocked state is derived from the
dependency and the task leaves the due list while it holds. It reappears the
moment the blocker closes, with nobody looking.

## D-8. Releasing is post-event work

Closing a task should not wait while its dependents are found and updated.
The release runs after the write, which is what ADR-078 requires of a
post-event listener anyway.
