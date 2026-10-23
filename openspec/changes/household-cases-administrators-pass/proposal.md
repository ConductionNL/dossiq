# Proposal: household-cases-administrators-pass

## Why

`HouseholdCasesController` documents that the household endpoints answer to a
reader who may see the social domain's sensitive fields "(dossiq-sensitive, or
an administrator)" and who may read the case. The code asked only for
`dossiq-sensitive`, so an administrator was refused. Coordinator decision
(23 Oct): the documented contract holds.

## What changes

An instance administrator passes the household check as a member of
`dossiq-sensitive` does. The case read check still applies to everyone. Nobody
outside the group or the administrators gains access.
