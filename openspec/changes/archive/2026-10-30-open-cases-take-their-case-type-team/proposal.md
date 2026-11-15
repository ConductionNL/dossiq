# Open cases take their case type's handling team

## Why

A case takes its team (`assignedGroup`) from the case type's
`handling.defaultGroup` when it is filed (`CaseOpeningDefaults`). On the
upgraded Zuiddrecht instance the Woo case type stayed at version 1 without a
handling block until `seed-objects-and-packages-reach-upgraded-instances`
landed. Requests filed through the portal in that window were written without a
team. The case type now names `behandelaars`, but those cases keep an empty
team, so they never reach that team's queue.

Read access is not the gap: the handling team rule on the case schema matches
on `caseType` (`CaseTeamAccessRules`) and is written again after every import
and case type save, so it covers these cases as soon as the case type carries
its handling block. The team on the case is what is missing.

## What changes

- A repair step, `AssignOpenCasesTheirCaseTypeTeam`, fills `assignedGroup` on
  every open case that has none with its case type's current handling group.
  It runs on install and on upgrade, after the package install, and writes only
  that field.

## Impact

- An existing instance gets it on the next `occ upgrade`, or at once with
  `occ maintenance:repair`.
