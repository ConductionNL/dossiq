# Design: case-type-handling-teams

## Which relation, and why

Three things in dossiq already sit between a team and a case type. The brief
was to reuse one in effect rather than invent a parallel one.

1. **`handling.defaultGroup` on the case type (chosen).** "The group a new case
   of this type goes to", a Nextcloud group id, read by `CaseTypeHandling` and
   through it by `AssigneeResolver`. It is a statement about handling, it is on
   the case type, and its unit is the same team every other handling path uses
   (`TeamDirectory` and `CaseHandoverController` for handovers,
   `CaseCustodyController`, and the board `DqAfdelingen` which reads teams
   "uit Nextcloud-groepen").
   Its one limit is that it names one team. This change adds `handling.teams`
   beside it for the others, and counts the default group in, so the existing
   value is the first half of the link and nothing is migrated.

2. **`caseType.rightsMatrix` (rejected).** Department by role by
   confidentiality, with OpenRegister verbs. It answers "who may", not "who
   handles": a Toezichthouder may read every permit case and handles none. Its
   own description says dossiq computes no effective permission from these
   rows, because OpenRegister owns the grant and its provenance; deriving a
   team's case types from it would be exactly that computation. Its key is also
   an `organisatieRol` or a department name, not a group a user can be found in
   without a second lookup.

3. **`case.assignedGroup` on open cases (rejected).** "The case types my team
   has cases in" could be counted from cases. It changes with every case that
   opens or closes, so the picker would offer a case type on Monday and drop it
   on Friday when the last case closed; it costs a search per load; and
   `assignedGroup` is declared `$ref: organisatieRol` while the handling code
   uses group ids, so the join is not reliable today.

## The user's teams

`IGroupManager::getUserGroupIds($user)`. No dossiq membership table:
`medewerkerRolToewijzing` exists for the mandate matrix and is not what the
handling code reads.

## The rule

```
teams(caseType)  = [handling.defaultGroup (with its legacy fallback)] + handling.teams, unique, non-empty
visible          = current case types under OpenRegister RBAC (unchanged)
handled          = visible where teams(caseType) ∩ userGroups ≠ ∅
offered          = handled when non-empty, else visible
```

`offered` replaces `visible` in all three places the menu reads it: the
picker's `available`, the read of `chosen`, and the cleaning on save. So a
user who leaves a team loses its case types from the menu on the next load,
and the picker never lists a chosen row it would not offer. The Woo default for
a user who never chose follows the same rule: it shows when it is offered.

"In no team" means: in none of the groups any visible case type names. That
covers a user with no groups and a user whose groups handle nothing, and
both get the fallback rather than an empty picker.

## Admin UI

`GeneralTab.vue` gains "Handling teams", an `NcSelect` with `multiple`, options
from OCS `cloud/groups/details` (the case type form is admin-only). It writes
the whole `handling` block back with `teams` replaced, so the other switches
are kept. The default group stays where it is set today; the field's hint says
it always counts.

## Board

`DqZaaktype` details card ("Gegevens") gains a row "Behandelende teams:
Vergunningen, Toezicht en handhaving". The board text and the code label
agree: nl "Behandelende teams", en "Handling teams".
