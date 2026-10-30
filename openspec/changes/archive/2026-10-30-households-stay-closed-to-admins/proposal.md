# Proposal: households-stay-closed-to-admins

## Why

`HouseholdCasesController`'s docblock said a household answers to
"dossiq-sensitive, or an administrator", while the code (rightly) asks only for
`dossiq-sensitive`. Ruben decided on 23 Oct that households stay closed to
administrators. The comment now says what the code does, and a test holds it.

## What changes

Docblock only, plus a regression test over the real guard: an administrator
outside `dossiq-sensitive` gets 403 `household-not-allowed`. No behaviour change.
